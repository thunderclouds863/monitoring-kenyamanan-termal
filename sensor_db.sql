CREATE DATABASE IF NOT EXISTS figflzel_data;

-- Users table
CREATE TABLE IF NOT EXISTS users (
    id INT AUTO_INCREMENT PRIMARY KEY,
    username VARCHAR(50) NOT NULL UNIQUE,
    email VARCHAR(100) NOT NULL UNIQUE,
    phone VARCHAR(15) NOT NULL UNIQUE,
    password VARCHAR(255) NOT NULL,
    profile_pic LONGBLOB,
    otp VARCHAR(6) NULL,
    is_verified TINYINT(1) DEFAULT 0,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    apikey VARCHAR(255) DEFAULT NULL,
    remember_token VARCHAR(255) DEFAULT NULL
);

-- Sensor readings table
CREATE TABLE IF NOT EXISTS sensor_readings (
    id BIGINT AUTO_INCREMENT PRIMARY KEY,
    temperature FLOAT,
    humidity FLOAT,
    wind_speed FLOAT,
    air_quality FLOAT,
    reading_time TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS alert_logs (
  id int(11) NOT NULL AUTO_INCREMENT,
  user_id int(11) NOT NULL,
  sensor_id BIGINT NOT NULL,
  CONSTRAINT fk_sensor_id FOREIGN KEY (sensor_id) REFERENCES sensor_readings(id) ON DELETE CASCADE,
  message text NOT NULL,
  created_at datetime NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (id),
  KEY user_id (user_id),
  CONSTRAINT alert_logs_ibfk_1 FOREIGN KEY (user_id) REFERENCES users (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

CREATE TABLE IF NOT EXISTS last_alerts (
    user_id INT NOT NULL,
    sensor_type VARCHAR(20) NOT NULL,
    last_value FLOAT,
    last_status ENUM('normal', 'abnormal') DEFAULT 'normal',
    last_notified_at DATETIME,
    PRIMARY KEY (user_id, sensor_type),
    CONSTRAINT fk_last_alerts_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
);

-- Settings table
CREATE TABLE IF NOT EXISTS settings (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    suhu_min FLOAT NOT NULL DEFAULT 25,
    suhu_max FLOAT NOT NULL DEFAULT 30,
    kelembapan_min FLOAT NOT NULL DEFAULT 50,
    kelembapan_max FLOAT NOT NULL DEFAULT 90,
    wind_speed_min FLOAT NOT NULL DEFAULT 1,
    wind_speed_max FLOAT NOT NULL DEFAULT 5,
    air_quality_max FLOAT NOT NULL DEFAULT 450,
    interval_update INT NOT NULL DEFAULT 5,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    CONSTRAINT fk_settings_user FOREIGN KEY (user_id)
        REFERENCES users(id) ON DELETE CASCADE
);

ALTER TABLE settings
ADD cooldown_time INT DEFAULT 600,  -- dalam detik, misalnya 10 menit
ADD delta_threshold FLOAT DEFAULT 10,  -- perubahan signifikan
ADD sensitivity_level ENUM('normal', 'sensitif') DEFAULT 'normal';


DELIMITER $$

CREATE TRIGGER after_user_insert
AFTER INSERT ON users
FOR EACH ROW
BEGIN
    INSERT INTO settings (user_id, suhu_min, suhu_max, kelembapan_min, kelembapan_max, wind_speed_min, wind_speed_max, air_quality_max, interval_update)
    VALUES (NEW.id, 25, 30, 50, 90, 1, 5, 450, 5);
END$$

DELIMITER ;
