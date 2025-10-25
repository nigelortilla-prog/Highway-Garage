CREATE TABLE cars (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    car_model VARCHAR(100) NOT NULL,
    plate_number VARCHAR(30) NOT NULL,
    car_color VARCHAR(30) NOT NULL,
    car_version VARCHAR(30),
    car_image VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(id)
);