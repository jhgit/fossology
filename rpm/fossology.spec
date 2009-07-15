#
# $Id$
#

Name:           fossology
Version:        1.1.0
Release:        .centos5
License:        GPLv2
Group:          Applications/Engineering
Url:            http://www.fossology.org
Source:         ftp://bl465c-7.test/src/%{name}-%{version}.tar.gz
#PBPATCHSRC
BuildRoot:      %{_tmppath}/%{name}-%{version}-%{release}-root-%(id -u -n)
Requires:       postgresql >= 8.1.11 php >= 5.1.6 php-pear >= 5.16 php-pgsql >= 5.1.6 libxml2 binutils bzip2 cpio mkisofs poppler-utils rpm tar unzip gzip httpd which file postgresql-server >= 8.1.11 smtpdaemon
BuildRequires:  postgresql-devel >= 8.1.11 libxml2 gcc make perl perl-Text-Template subversion file libextractor-devel
Summary:        FOSSology is a licenses exploration tool
Summary(fr):    FOSSology est un outil d'exploration de licenses

%package devel
Summary:        Devel part of FOSSology (a licenses exploration tool)
Summary(fr):    Partie dedévelopment de FOSSology, outil d'exploration de licenses
Group:          Applications/Engineering

%description
"FOSSology is a licenses exploration tool"

%description -l fr
FOSSology est un outil d'exploration de licenses

%description devel
Devel part.
"FOSSology is a licenses exploration tool"

%description -l fr devel
Partie développement de FOSSology, outil d'exploration de licenses

%prep
%setup -q
#PBPATCHCMD

%build
make SYSCONFDIR=%{_sysconfdir} PREFIX=%{_usr} LOCALSTATEDIR=%{_var}
#make %{?_smp_mflags} SYSCONFDIR=%{_sysconfdir}

%install
%{__rm} -rf $RPM_BUILD_ROOT
make DESTDIR=$RPM_BUILD_ROOT PREFIX=%{_usr} SYSCONFDIR=%{_sysconfdir} LOCALSTATEDIR=%{_var} LIBDIR=%{_libdir} install
mkdir -p $RPM_BUILD_ROOT/%{_sysconfdir}/httpd/conf.d
cat > $RPM_BUILD_ROOT/%{_sysconfdir}/httpd/conf.d/fossology.conf << EOF
Alias /repo/ /usr/share/fossology/www/
<Directory "/usr/share/fossology/www">
	AllowOverride None
	Options FollowSymLinks MultiViews
	Order allow,deny
	Allow from all
	# uncomment to turn on php error reporting 
	#php_flag display_errors on
	#php_value error_reporting 2039
</Directory>
EOF
cp utils/fo-cleanold $RPM_BUILD_ROOT/%{_usr}/lib/fossology/

#rm -f $RPM_BUILD_ROOT/%{_sysconfdir}/default/fossology

%clean
%{__rm} -rf $RPM_BUILD_ROOT

%files
%defattr(-,root,root)
%doc ChangeLog
%doc COPYING COPYING.LGPL HACKING README INSTALL INSTALL.multi LICENSE 
#AUTHORS NEWS
%config(noreplace) %{_sysconfdir}/httpd/conf.d/*.conf
%config(noreplace) %{_sysconfdir}/cron.d/*
%config(noreplace) %{_sysconfdir}/fossology/*
%dir %{_sysconfdir}/fossology
%dir %{_usr}/lib/fossology
%dir %{_datadir}/fossology
%{_sysconfdir}/init.d/*
%{_usr}/lib/fossology/*
%{_datadir}/fossology/*
%{_bindir}/*
%{_mandir}/man1/*

%files devel
%{_includedir}/*
%{_libdir}/*.a

%post
# Check postgresql is running
LANGUAGE=C /etc/init.d/postgresql status 2>&1 | grep -q stop
if [ $? -eq 0 ]; then
	/etc/init.d/postgresql start
fi
chkconfig --add postgresql

# We suppose that we use the local postgresql installed on the same machine.
cat >> /var/lib/pgsql/data/pg_hba.conf << EOF
# Added for FOSSology connection
# Local connections
local   all         all                               md5
# IPv4 local connections:
host    all         all         127.0.0.1/32          md5

EOF
perl -pi -e 's|(host\s+all\s+all\s+127.0.0.1/32\s+ident\s+sameuser)|#$1|' /var/lib/pgsql/data/pg_hba.conf

# Now restart again postgresql
# We have do it here in order to let postgresql configure itself correctly
# in case it wasn't already installed
/etc/init.d/postgresql restart

# Adjust PHP config (described in detail in section 2.1.5)
grep -qw allow_call_time_pass_reference /etc/php.ini
if [ $? -eq 0 ]; then
	perl -pi -e "s/^[#\s]*allow_call_time_pass_reference.*=.*/allow_call_time_pass_reference = On/" /etc/php.ini
else
	echo "allow_call_time_pass_reference = On" >> /etc/php.ini
fi

# Add apache config for fossology (described in detail in section 2.1.6) - done in install
# Run the postinstall script
/usr/lib/fossology/fo-postinstall

# Adds user httpd to fossy group
#useradd -G fossy httpd
#perl -pi -e 's/^fossy:x:([0-9]+):/fossy:x:$1:httpd/' /etc/group

# httpd is also assumed to run locally
LANGUAGE=C /etc/init.d/httpd status 2>&1 | grep -q stop
if [ $? -eq 0 ]; then
	/etc/init.d/httpd start
else
	/etc/init.d/httpd reload
fi
chkconfig --add httpd

# Create logfile to avoid issues later on
#touch %{_var}/log/fossology
# Handle logfile owner correctly
#chown fossy:fossy %{_var}/log/fossology

# Test that things are installed correctly
/usr/lib/fossology/fossology-scheduler -t
if [ $? -ne 0 ]; then
	exit -1
fi

chkconfig --add fossology
/etc/init.d/fossology start

%preun
# If FOSSology is running, stop it before removing.
/etc/init.d/fossology stop
chkconfig --del fossology 2>&1 > /dev/null

# We should do some cleanup here (fossy account ...)
/usr/lib/fossology/fo-cleanold

%changelog


