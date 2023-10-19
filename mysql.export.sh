#!/bin/bash

docker exec -i mysql mysqldump -u root -pp0epsteen db_app > tests/data/mysql.sql