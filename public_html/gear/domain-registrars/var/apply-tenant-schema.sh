#!/bin/sh
set -eu
for db in $(ls /var/lib/mysql | grep '^tenant_'); do
  echo "APPLY:$db"
  MYSQL_PWD='HfgksaU&9w3k*5142' mariadb -h 10.16.55.79 -u biometrics "$db" < /tmp/tenant-schema.sql
done
