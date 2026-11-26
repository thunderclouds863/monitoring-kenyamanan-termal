-- Data demo: akun terverifikasi + 7 hari data sensor sintetis + simulasi sensor tiap menit
-- Login: demo@demo.local / demo123
INSERT INTO users (id, username, email, phone, password, is_verified) VALUES
(1, 'demo', 'demo@demo.local', '081200000000', '$2y$10$52hboDnXS8GD5GyOOao2AO6mH7qI2M0ROpfd4EL8tO.W5hBADvWv6', 1);
-- Note: trigger after_user_insert automatically creates default settings row for user 1.

SET SESSION max_recursive_iterations = 5000;
-- 7 hari terakhir, tiap 10 menit, pola harian (siang lebih panas & kering)
INSERT INTO sensor_readings (temperature, humidity, wind_speed, air_quality, reading_time)
WITH RECURSIVE t(n) AS (SELECT 0 UNION ALL SELECT n + 1 FROM t WHERE n < 1007)
SELECT
  ROUND(28 + 3 * SIN(2 * PI() * (HOUR(NOW() - INTERVAL n*10 MINUTE) - 9) / 24) + (RAND() - 0.5), 1),
  ROUND(72 - 10 * SIN(2 * PI() * (HOUR(NOW() - INTERVAL n*10 MINUTE) - 9) / 24) + (RAND() - 0.5) * 4, 1),
  ROUND(GREATEST(0, 1.5 + (RAND() - 0.5) * 2), 1),
  ROUND(220 + RAND() * 120, 0),
  NOW() - INTERVAL n*10 MINUTE
FROM t;

-- Simulasi Arduino: satu pembacaan baru tiap menit selama container hidup
CREATE EVENT IF NOT EXISTS demo_sensor_feed ON SCHEDULE EVERY 1 MINUTE DO
  INSERT INTO sensor_readings (temperature, humidity, wind_speed, air_quality)
  VALUES (ROUND(28 + 3 * SIN(2 * PI() * (HOUR(NOW()) - 9) / 24) + (RAND() - 0.5), 1),
          ROUND(72 - 10 * SIN(2 * PI() * (HOUR(NOW()) - 9) / 24) + (RAND() - 0.5) * 4, 1),
          ROUND(GREATEST(0, 1.5 + (RAND() - 0.5) * 2), 1),
          ROUND(220 + RAND() * 120, 0));
