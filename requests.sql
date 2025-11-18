CREATE TABLE requests (
    id INT AUTO_INCREMENT PRIMARY KEY,
    user_id INT NOT NULL,
    requestor_name VARCHAR(255) NOT NULL,
    requestor_email VARCHAR(255) NOT NULL,
    destination VARCHAR(255) NOT NULL,
    purpose TEXT NOT NULL,
    request_date DATETIME DEFAULT CURRENT_TIMESTAMP,
    status ENUM('pending', 'approved', 'rejected', 'approved_pending_dispatch', 'pending_dispatch_assignment', 'pending_admin_approval', 'rejected_reassign_dispatch', 'rejected_new_request') DEFAULT 'pending_dispatch_assignment',
    rejection_reason VARCHAR(255) NULL,
    assigned_vehicle_id INT NULL,
    assigned_driver_id INT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
    FOREIGN KEY (assigned_vehicle_id) REFERENCES vehicles(id) ON DELETE SET NULL,
    FOREIGN KEY (assigned_driver_id) REFERENCES drivers(id) ON DELETE SET NULL
);

CREATE INDEX idx_request_status ON requests (status);
