
<?php
$conn = new mysqli("localhost", "root", "", "practical12");

if ($conn->connect_error) {
    die("Database connection failed: " . $conn->connect_error);
}

$sql = "CREATE TABLE IF NOT EXISTS events (
    id INT AUTO_INCREMENT PRIMARY KEY,
    event_name VARCHAR(150) NOT NULL,
    event_date DATE NOT NULL,
    category VARCHAR(100) NOT NULL,
    description TEXT,
    status ENUM('Open', 'Closed') NOT NULL DEFAULT 'Open',
    poster VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB";

if (!$conn->query($sql)) {
    die("Table creation failed: " . $conn->error);
}

$count = $conn->query("SELECT COUNT(*) AS total FROM events");
$total = $count->fetch_assoc()['total'];

if ($total == 0) {
    $conn->query("INSERT INTO events
        (event_name, event_date, category, description, status)
        VALUES
        ('Web Development Workshop', '2026-11-15',
         'Workshop', 'Learn HTML, CSS and JavaScript.', 'Open'),
        ('Cybersecurity Seminar', '2026-11-20',
         'Seminar', 'Introduction to online security.', 'Open'),
        ('Coding Competition', '2026-12-05',
         'Competition', 'Programming competition for students.', 'Closed')");
}

echo "Setup completed successfully. Event records are ready.";

$conn->close();
?>
