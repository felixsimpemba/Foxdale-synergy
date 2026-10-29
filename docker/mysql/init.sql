-- Separate database for the test suite.
CREATE DATABASE IF NOT EXISTS foxdale_incidents_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON foxdale_incidents_test.* TO 'foxdale'@'%';
FLUSH PRIVILEGES;
