CREATE TABLE requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    requestor_name VARCHAR(255) NOT NULL,
    requestor_email VARCHAR(255) NOT NULL,
    destination VARCHAR(255) NOT NULL,
    purpose TEXT NOT NULL,
    request_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    status ENUM(
        'pending_dispatch_assignment',
        'pending_admin_approval',
        'approved',
        'rejected_new_request',
        'rejected_reassign_dispatch',
        'rejected',
        'cancelled'
    ) DEFAULT 'pending_dispatch_assignment',
    rejection_reason VARCHAR(255) NULL,
    departure_date DATE NULL,
    return_date DATE NULL,
    passenger_count INT DEFAULT 0,
    passenger_names TEXT NULL,
    assigned_vehicle_id INT NULL,
    assigned_driver_id INT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_vehicle_id) REFERENCES vehicles(id) ON DELETE SET NULL,
    FOREIGN KEY (assigned_driver_id) REFERENCES drivers(id) ON DELETE SET NULL
);

CREATE INDEX idx_request_status ON requests (status);

CREATE TABLE request_audit_logs (
    id INT AUTO_INCREMENT PRIMARY KEY,
    request_id INT NOT NULL,
    action VARCHAR(100) NOT NULL,
    actor_id INT NULL,
    actor_role VARCHAR(50) NULL,
    actor_name VARCHAR(255) NULL,
    notes TEXT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE
);

CREATE INDEX idx_request_audit_request_id ON request_audit_logs (request_id);
