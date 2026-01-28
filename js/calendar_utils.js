/**
 * Calendar Utilities - Shared modal and formatting functions
 * Used by: dashboardX.php, dispatch_dashboard.php
 */

const CalendarUtils = {
    /**
     * Set text content of modal element
     */
    setModalText(id, value) {
        const el = document.getElementById(id);
        if (el) {
            el.textContent = value || '----';
        }
    },

    /**
     * Format date string to readable format
     */
    formatDate(dateStr) {
        if (!dateStr) return 'Date TBD';
        const date = new Date(`${dateStr}T00:00:00`);
        if (Number.isNaN(date.getTime())) {
            return 'Date TBD';
        }
        return date.toLocaleDateString(undefined, { 
            month: 'short', 
            day: 'numeric', 
            year: 'numeric' 
        });
    },

    /**
     * Format date range
     */
    formatRange(start, end) {
        if (!start) return 'Date TBD';
        if (!end || end === start) return this.formatDate(start);
        return `${this.formatDate(start)} - ${this.formatDate(end)}`;
    },

    /**
     * Format datetime timestamp
     */
    formatTimestamp(dateTimeStr) {
        if (!dateTimeStr) return '';
        const date = new Date(dateTimeStr.replace(' ', 'T'));
        if (Number.isNaN(date.getTime())) {
            return dateTimeStr;
        }
        return date.toLocaleString(undefined, {
            month: 'short',
            day: 'numeric',
            year: 'numeric',
            hour: 'numeric',
            minute: '2-digit'
        });
    },

    /**
     * Format action label from snake_case
     */
    formatActionLabel(action) {
        if (!action) return 'Update';
        return action.toLowerCase()
            .split('_')
            .map(word => word.charAt(0).toUpperCase() + word.slice(1))
            .join(' ');
    },

    /**
     * Populate calendar modal with request details
     * @param {Object} details - Request details object
     * @param {string} modalPrefix - Prefix for modal element IDs ('calendar' or 'dispatch')
     */
    populateModal(details, modalPrefix = 'calendar') {
        if (!details) return;

        this.setModalText(`${modalPrefix}ModalVehicle`, details.vehicle || details.plate || 'Vehicle TBD');
        this.setModalText(`${modalPrefix}ModalPlate`, details.plate || 'Plate TBD');
        this.setModalText(`${modalPrefix}ModalRequestor`, details.requestor || '----');
        this.setModalText(`${modalPrefix}ModalEmail`, details.email || '----');
        this.setModalText(`${modalPrefix}ModalDestination`, details.destination || 'Destination TBD');
        this.setModalText(`${modalPrefix}ModalDriver`, details.driver || 'Pending Assignment');
        this.setModalText(`${modalPrefix}ModalDates`, this.formatRange(details.start, details.end));
        this.setModalText(`${modalPrefix}ModalStatus`, details.status || 'Approved');
        this.setModalText(`${modalPrefix}ModalPurpose`, details.purpose || '----');
        this.setModalText(`${modalPrefix}ModalPassengers`, details.passengers || '----');

        // Set request ID for cancel functionality
        const requestIdElement = document.getElementById(`${modalPrefix}ModalRequestId`);
        if (requestIdElement) {
            requestIdElement.value = details.id || '';
        }

        // Populate audit timeline
        this.populateAuditTimeline(details.audit, `${modalPrefix}AuditTimeline`);
    },

    /**
     * Populate audit timeline in modal
     */
    populateAuditTimeline(auditLogs, containerId) {
        const container = document.getElementById(containerId);
        if (!container) return;

        container.innerHTML = '';

        if (Array.isArray(auditLogs) && auditLogs.length) {
            auditLogs.slice(0, 5).forEach(entry => {
                const auditEntry = document.createElement('div');
                auditEntry.className = 'audit-entry';

                // Title
                const title = document.createElement('div');
                title.className = 'audit-entry__title';
                title.textContent = this.formatActionLabel(entry.action);
                auditEntry.appendChild(title);

                // Meta (actor + timestamp)
                const meta = document.createElement('div');
                meta.className = 'audit-entry__meta';

                // Actor
                const actorSpan = document.createElement('span');
                const actorIcon = document.createElement('i');
                actorIcon.className = 'fas fa-user me-1';
                actorSpan.appendChild(actorIcon);
                actorSpan.appendChild(document.createTextNode(entry.actor_name || 'System'));
                meta.appendChild(actorSpan);

                // Timestamp
                const timestampLabel = this.formatTimestamp(entry.created_at);
                if (timestampLabel) {
                    const timeSpan = document.createElement('span');
                    const timeIcon = document.createElement('i');
                    timeIcon.className = 'fas fa-clock me-1';
                    timeSpan.appendChild(timeIcon);
                    timeSpan.appendChild(document.createTextNode(timestampLabel));
                    meta.appendChild(timeSpan);
                }

                auditEntry.appendChild(meta);

                // Notes
                if (entry.notes) {
                    const notes = document.createElement('div');
                    notes.className = 'audit-entry__notes';
                    notes.textContent = entry.notes;
                    auditEntry.appendChild(notes);
                }

                container.appendChild(auditEntry);
            });
        } else {
            const emptyState = document.createElement('p');
            emptyState.className = 'text-muted small mb-0';
            emptyState.textContent = 'No audit activity recorded.';
            container.appendChild(emptyState);
        }
    }
};

// Export for use in other scripts
if (typeof module !== 'undefined' && module.exports) {
    module.exports = CalendarUtils;
}