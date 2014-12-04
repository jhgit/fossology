<?php
/*
Copyright (C) 2014, Siemens AG

This program is free software; you can redistribute it and/or
modify it under the terms of the GNU General Public License
version 2 as published by the Free Software Foundation.

This program is distributed in the hope that it will be useful,
but WITHOUT ANY WARRANTY; without even the implied warranty of
MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
GNU General Public License for more details.

You should have received a copy of the GNU General Public License along
with this program; if not, write to the Free Software Foundation, Inc.,
51 Franklin Street, Fifth Floor, Boston, MA  02110-1301, USA.
*/

namespace Fossology\Lib\BusinessRules;

use Fossology\Lib\Dao\ClearingDao;
use Fossology\Lib\Data\Clearing\ClearingEventTypes;
use Fossology\Lib\Data\Clearing\ClearingResult;
use Fossology\Lib\Data\ClearingDecision;
use Fossology\Lib\Data\DecisionScopes;
use Fossology\Lib\Data\DecisionTypes;
use Fossology\Lib\Data\Tree\ItemTreeBounds;
use Fossology\Lib\Db\DbManager;
use Fossology\Lib\Exception;
use Fossology\Lib\Util\Object;

class ClearingDecisionProcessor extends Object
{
  const NO_LICENSE_KNOWN_DECISION_TYPE = 2;

  /** @var ClearingDao */
  private $clearingDao;

  /** @var AgentLicenseEventProcessor */
  private $agentLicenseEventProcessor;

  /** @var ClearingEventProcessor */
  private $clearingEventProcessor;

  /** @var DbManager */
  private $dbManager;

  /**
   * @param ClearingDao $clearingDao
   * @param AgentLicenseEventProcessor $agentLicenseEventProcessor
   * @param ClearingEventProcessor $clearingEventProcessor
   */
  public function __construct(ClearingDao $clearingDao, AgentLicenseEventProcessor $agentLicenseEventProcessor, ClearingEventProcessor $clearingEventProcessor, DbManager $dbManager)
  {
    $this->clearingDao = $clearingDao;
    $this->agentLicenseEventProcessor = $agentLicenseEventProcessor;
    $this->clearingEventProcessor = $clearingEventProcessor;
    $this->dbManager = $dbManager;
  }

  /**
   * @param ItemTreeBounds $itemTreeBounds
   * @param int $groupId
   * @return bool
   */
  public function hasUnhandledScannerDetectedLicenses(ItemTreeBounds $itemTreeBounds, $groupId) {
    $userEvents = $this->clearingDao->getRelevantClearingEvents($itemTreeBounds, $groupId);
    $scannerDetectedEvents = $this->agentLicenseEventProcessor->getScannerEvents($itemTreeBounds);
    foreach(array_keys($scannerDetectedEvents) as $licenseId){
      if (!array_key_exists($licenseId, $userEvents))
      {
        return true;
      }
    }
    return false;
  }

  private function insertClearingEventsForAgentFindings(ItemTreeBounds $itemBounds, $userId, $groupId, $remove = false, $type = ClearingEventTypes::AGENT, $removedIds=array())
  {
    $eventIds = array();
    foreach($this->agentLicenseEventProcessor->getScannerEvents($itemBounds) as $licenseId => $scannerEvents)
    {
      if (array_key_exists($licenseId, $removedIds))
      {
        continue;
      }
      $scannerLicenseRef = $scannerEvents[0]->getLicenseRef();
      $eventIds[$scannerLicenseRef->getId()] = $this->clearingDao->insertClearingEvent($itemBounds->getItemId(), $userId, $groupId, $scannerLicenseRef->getId(), $remove, $type);
    }
    return $eventIds;
  }

  /**
   * @param ClearingDecision $decision
   * @param int $type
   * @param int[] $clearingEventIds
   * @return boolean
   */
  private function clearingDecisionIsDifferentFrom(ClearingDecision $decision, $type, $clearingEventIds)
  {
    $clearingEvents = $decision->getClearingEvents();
    if (count($clearingEvents) != count($clearingEventIds))
      return true;

    foreach($clearingEvents as $clearingEvent) {
      if (false === array_search($clearingEvent->getEventId(), $clearingEventIds))
        return true;
    }
    return $type !== $decision->getType();
  }

  /**
   * @param ItemTreeBounds $itemBounds
   * @param int $userId
   * @param int $type
   * @param boolean $global
   */
  public function makeDecisionFromLastEvents(ItemTreeBounds $itemBounds, $userId, $groupId, $type, $global)
  {
    if ($type < self::NO_LICENSE_KNOWN_DECISION_TYPE)
    {
      return;
    }

    $needTransaction = !$this->dbManager->isInTransaction();
    if ($needTransaction)
    {
      $this->dbManager->begin();
    }

    $itemId = $itemBounds->getItemId();

    if ($type === self::NO_LICENSE_KNOWN_DECISION_TYPE)
    {
      $type = DecisionTypes::IDENTIFIED;
      $clearingEventIds = $this->insertClearingEventsForAgentFindings($itemBounds, $userId, $groupId, true, ClearingEventTypes::USER);
    } else {
      $previousEvents = $this->clearingDao->getRelevantClearingEvents($itemBounds, $groupId);
      $clearingEventIds = $this->insertClearingEventsForAgentFindings($itemBounds, $userId, $groupId, false, ClearingEventTypes::USER, $previousEvents);
      foreach($previousEvents as $clearingEvent) {
        $clearingEventIds[$clearingEvent->getLicenseId()] = $clearingEvent->getEventId();
      }
    }

    $currentDecision = $this->clearingDao->getRelevantClearingDecision($itemBounds, $groupId);

    if (null === $currentDecision || $this->clearingDecisionIsDifferentFrom($currentDecision, $type, $clearingEventIds))
    {
      $scope = $global ? DecisionScopes::REPO : DecisionScopes::ITEM;
      $this->clearingDao->createDecisionFromEvents($itemBounds->getItemId(), $userId, $groupId, $type, $scope,
      $clearingEventIds);
    }
    else
    {
      $this->clearingDao->removeWipClearingDecision($itemId, $groupId);
    }

    if ($needTransaction)
    {
      $this->dbManager->commit();
    }
  }

  /**
   * @param ItemTreeBounds $itemTreeBounds
   * @param int $groupId
   * @return array
   */
  public function getCurrentClearings(ItemTreeBounds $itemTreeBounds, $groupId)
  {
    $agentEvents = $this->agentLicenseEventProcessor->getScannerEvents($itemTreeBounds);
    $events = $this->clearingDao->getRelevantClearingEvents($itemTreeBounds, $groupId);

    $addedResults = array();
    $removedResults = array();
    
    foreach (array_unique(array_merge(array_keys($events), array_keys($agentEvents))) as $licenseId)
    {
      $licenseDecisionEvent = array_key_exists($licenseId, $events) ? $events[$licenseId] : null;
      $agentClearingEvents = array_key_exists($licenseId, $agentEvents) ? $agentEvents[$licenseId] : array();
      
      if (($licenseDecisionEvent === null) && (count($agentClearingEvents) == 0))
        throw new Exception('not in merge');

      $licenseDecisionResult = new ClearingResult($licenseDecisionEvent, $agentClearingEvents);
      if ($licenseDecisionResult->isRemoved()) {
        $removedResults[$licenseId] = $licenseDecisionResult;
      } else {
        $addedResults[$licenseId] = $licenseDecisionResult;
      }
    }

    return array($addedResults, $removedResults);
  }




}