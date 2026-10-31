CREATE DATABASE IF NOT EXISTS practical12;
USE practical12;

CREATE TABLE IF NOT EXISTS events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_name VARCHAR(150) NOT NULL,
    event_date DATE NOT NULL,
    category VARCHAR(100) NOT NULL,
    description TEXT,
    status ENUM('Open', 'Closed') NOT NULL DEFAULT 'Open',
    poster VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;