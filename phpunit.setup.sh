docker exec -i mysql mysql -u root -pp0epsteen -e "DROP DATABASE db_app;CREATE DATABASE db_app;"

docker exec -i mysql mysql -u root -pp0epsteen db_app < tests/data/mysql.sql