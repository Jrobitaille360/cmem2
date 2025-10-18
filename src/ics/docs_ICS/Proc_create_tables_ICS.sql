DROP PROCEDURE IF EXISTS ResetICSTables;
DELIMITER //

CREATE PROCEDURE ResetICSTables()
BEGIN
-- Suppression des tables existantes
DROP TABLE IF EXISTS calendar_shares;
DROP TABLE IF EXISTS calendar_events;
DROP TABLE IF EXISTS calendars;

-- Table pour stocker les calendriers
CREATE TABLE calendars (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    timezone VARCHAR(50) DEFAULT 'America/Montreal',
    color VARCHAR(7) DEFAULT '#3174ad',
    visibility ENUM('public', 'private') DEFAULT 'private',
    share_token VARCHAR(64) UNIQUE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    INDEX idx_user_calendars (user_id),
    INDEX idx_share_token (share_token)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table pour les événements
CREATE TABLE calendar_events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    calendar_id INT NOT NULL,
    title VARCHAR(255) NOT NULL,
    description TEXT,
    start_datetime DATETIME NOT NULL,
    end_datetime DATETIME NOT NULL,
    all_day BOOLEAN DEFAULT FALSE,
    location VARCHAR(255),
    organizer_email VARCHAR(255),
    attendees JSON,
    recurrence_rule VARCHAR(255),
    status ENUM('confirmed', 'tentative', 'cancelled') DEFAULT 'confirmed',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (calendar_id) REFERENCES calendars(id) ON DELETE CASCADE,
    INDEX idx_calendar_events (calendar_id),
    INDEX idx_event_dates (start_datetime, end_datetime)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Table pour les partages de calendriers
CREATE TABLE calendar_shares (
    id INT AUTO_INCREMENT PRIMARY KEY,
    calendar_id INT NOT NULL,
    shared_with_user_id INT,
    shared_with_email VARCHAR(255),
    permission ENUM('read', 'write') DEFAULT 'read',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP NULL,
    FOREIGN KEY (calendar_id) REFERENCES calendars(id) ON DELETE CASCADE,
    FOREIGN KEY (shared_with_user_id) REFERENCES users(id) ON DELETE CASCADE,
    UNIQUE KEY unique_user_share (calendar_id, shared_with_user_id),
    UNIQUE KEY unique_email_share (calendar_id, shared_with_email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

END //
DELIMITER ;