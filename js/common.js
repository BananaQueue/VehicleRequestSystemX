/**
 * Common JavaScript Utilities
 * Shared across all pages: dashboardX.php, create_request.php, add/edit forms, etc.
 */

document.addEventListener('DOMContentLoaded', function() {
    
    // ============================================================================
    // AUTO-DISMISS ALERTS
    // ============================================================================
    
    /**
     * Auto-dismiss Bootstrap alerts after 5 seconds
     * Skips alerts with .alert-permanent class
     */
    const alerts = document.querySelectorAll('.alert:not(.alert-permanent)');
    alerts.forEach(function(alert) {
        const bsAlert = new bootstrap.Alert(alert);
        setTimeout(function() {
            const instance = bootstrap.Alert.getInstance(alert);
            if (instance) {
                instance.close();
            }
        }, 5000); // 5 seconds
    });

    
    // ============================================================================
    // BUTTON LOADING STATES
    // ============================================================================
    
    /**
     * Add loading states to .btn-modern buttons
     * Shows spinner and "Loading..." text on click
     */
    document.querySelectorAll('.btn-modern:not(.no-loading)').forEach(button => {
        button.addEventListener('click', function(e) {
            // Don't add loading state if button is already disabled or has onclick handler
            if (!this.classList.contains('disabled') && !this.onclick) {
                const originalText = this.innerHTML;
                this.dataset.originalText = originalText;
                this.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Loading...';
                this.classList.add('disabled', 'loading');
                
                // Remove loading state after 3 seconds (in case navigation fails)
                setTimeout(() => {
                    if (this.dataset.originalText) {
                        this.innerHTML = this.dataset.originalText;
                        this.classList.remove('disabled', 'loading');
                    }
                }, 3000);
            }
        });
    });

    
    // ============================================================================
    // FORM VALIDATION HELPERS
    // ============================================================================
    
    /**
     * Add visual feedback for form validation
     */
    const formInputs = document.querySelectorAll('.form-control, .form-select');
    formInputs.forEach(input => {
        // On blur, validate and add visual feedback
        input.addEventListener('blur', function() {
            if (this.validity.valid && this.value.trim() !== '') {
                this.classList.add('is-valid');
                this.classList.remove('is-invalid');
            } else if (this.value.trim() !== '') {
                this.classList.add('is-invalid');
                this.classList.remove('is-valid');
            }
        });

        // Clear validation classes on input
        input.addEventListener('input', function() {
            this.classList.remove('is-valid', 'is-invalid');
        });
    });

    
    // ============================================================================
    // KEYBOARD NAVIGATION IN FORMS
    // ============================================================================
    
    /**
     * Allow Enter key to move between form fields
     */
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' && e.target.matches('.form-control, .form-select')) {
            const formElements = Array.from(document.querySelectorAll('.form-control, .form-select'));
            const currentIndex = formElements.indexOf(e.target);
            
            // Move to next field if not the last one
            if (currentIndex < formElements.length - 1) {
                e.preventDefault();
                formElements[currentIndex + 1].focus();
            }
        }
    });

    
    // ============================================================================
    // CHARACTER COUNTER FOR TEXTAREAS
    // ============================================================================
    
    /**
     * Add character counters to textareas with maxlength
     * Creates a counter div if it doesn't exist
     */
    document.querySelectorAll('textarea[maxlength]').forEach(textarea => {
        const maxLength = parseInt(textarea.getAttribute('maxlength'));
        
        // Create counter element if it doesn't exist
        let counter = textarea.nextElementSibling;
        if (!counter || !counter.classList.contains('char-counter')) {
            counter = document.createElement('div');
            counter.className = 'char-counter text-muted small text-end mt-1';
            textarea.parentNode.insertBefore(counter, textarea.nextSibling);
        }
        
        // Update counter function
        const updateCounter = () => {
            const length = textarea.value.length;
            counter.textContent = `${length} / ${maxLength} characters`;
            
            // Remove all color classes
            counter.classList.remove('text-danger', 'text-warning', 'text-muted');
            
            // Color based on usage
            if (length > maxLength * 0.93) { // 93%+ = red (140/150)
                counter.classList.add('text-danger');
            } else if (length > maxLength * 0.80) { // 80%+ = warning (120/150)
                counter.classList.add('text-warning');
            } else {
                counter.classList.add('text-muted');
            }
        };
        
        // Initialize and listen for changes
        updateCounter();
        textarea.addEventListener('input', updateCounter);
    });

    
    // ============================================================================
    // PREVENT BACK/FORWARD CACHE ISSUES
    // ============================================================================
    
    /**
     * Force reload if page is loaded from back/forward cache
     * Prevents stale data issues
     */
    window.addEventListener('pageshow', function(event) {
        if (event.persisted || 
            (performance.navigation && performance.navigation.type === 2) ||
            (performance.getEntriesByType && 
             performance.getEntriesByType('navigation')[0] && 
             performance.getEntriesByType('navigation')[0].type === 'back_forward')) {
            window.location.reload();
        }
    });

    
    // ============================================================================
    // CLICK OUTSIDE TO CLOSE
    // ============================================================================
    
    /**
     * Close elements when clicking outside them
     * Add data-close-on-outside attribute to enable
     */
    document.addEventListener('click', function(e) {
        const closeables = document.querySelectorAll('[data-close-on-outside]');
        closeables.forEach(element => {
            if (!element.contains(e.target) && element.style.display !== 'none') {
                element.style.display = 'none';
            }
        });
    });

    
    // ============================================================================
    // ANIMATE STAT CARDS ON LOAD
    // ============================================================================
    
    /**
     * Animate stat cards with staggered delay
     */
    const statCards = document.querySelectorAll('.stat-card');
    statCards.forEach((card, index) => {
        setTimeout(() => {
            card.style.animation = 'fadeInUp 0.6s ease-out forwards';
        }, index * 100);
    });

});


// ============================================================================
// UTILITY FUNCTIONS (Available globally)
// ============================================================================

/**
 * Debounce function to limit rapid function calls
 */
function debounce(func, wait) {
    let timeout;
    return function executedFunction(...args) {
        const later = () => {
            clearTimeout(timeout);
            func(...args);
        };
        clearTimeout(timeout);
        timeout = setTimeout(later, wait);
    };
}

/**
 * Show/hide element with fade effect
 */
function toggleFade(element, show) {
    if (show) {
        element.style.display = 'block';
        setTimeout(() => element.style.opacity = '1', 10);
    } else {
        element.style.opacity = '0';
        setTimeout(() => element.style.display = 'none', 300);
    }
}

/**
 * Scroll to element smoothly
 */
function scrollToElement(elementId) {
    const element = document.getElementById(elementId);
    if (element) {
        element.scrollIntoView({ 
            behavior: 'smooth', 
            block: 'start' 
        });
    }
}

/**
 * Copy text to clipboard
 */
async function copyToClipboard(text) {
    try {
        await navigator.clipboard.writeText(text);
        return true;
    } catch (err) {
        console.error('Failed to copy:', err);
        return false;
    }
}

/**
 * Format number with commas
 */
function numberWithCommas(x) {
    return x.toString().replace(/\B(?=(\d{3})+(?!\d))/g, ",");
}

/**
 * Validate email format
 */
function isValidEmail(email) {
    const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
    return re.test(email);
}

/**
 * Validate phone format (09XXXXXXXXX)
 */
function isValidPhone(phone) {
    const re = /^09\d{9}$/;
    return re.test(phone);
}

// Add fadeInUp animation if not in CSS
if (!document.styleSheets[0].cssRules || 
    !Array.from(document.styleSheets[0].cssRules).some(rule => 
        rule.name === 'fadeInUp')) {
    const style = document.createElement('style');
    style.textContent = `
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
    `;
    document.head.appendChild(style);
}