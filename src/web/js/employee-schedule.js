/**
 * Employee Schedule Management - Frontend JavaScript
 * 
 * Handles schedule CRUD operations, modal management, and employee selection
 */

(function() {
    'use strict';

    // Wait for DOM and data to be ready
    if (typeof window.bookedScheduleData === 'undefined') {
        console.error('bookedScheduleData not found');
        return;
    }

    const data = window.bookedScheduleData;
    let schedules = data.schedules || [];
    let currentEmployeeId = data.employeeId;
    const isAdminMode = data.isAdminMode;
    const strings = data.strings || {};

    // DOM elements
    const schedulesList = document.getElementById('schedules-list');
    const addBtn = document.getElementById('add-schedule-btn');
    const scheduleModal = document.getElementById('schedule-modal');
    const scheduleForm = document.getElementById('schedule-form');
    const modalCloseBtn = document.querySelector('.booked-modal-close');
    const modalCancelBtn = document.querySelector('.booked-modal-cancel');
    const modalOverlay = document.querySelector('.booked-modal-overlay');
    const employeeSelect = document.getElementById('employee-select');

    // Initialize
    document.addEventListener('DOMContentLoaded', function() {
        // If we have an employee, load schedules
        if (currentEmployeeId) {
            loadSchedules();
        }

        // Event listeners
        if (addBtn) {
            addBtn.addEventListener('click', function() {
                showScheduleEditor(null);
            });
        }

        if (modalCloseBtn) {
            modalCloseBtn.addEventListener('click', closeModal);
        }

        if (modalCancelBtn) {
            modalCancelBtn.addEventListener('click', closeModal);
        }

        if (modalOverlay) {
            modalOverlay.addEventListener('click', closeModal);
        }

        if (scheduleForm) {
            scheduleForm.addEventListener('submit', handleScheduleSubmit);
        }

        // Employee selector (admin mode)
        if (employeeSelect) {
            employeeSelect.addEventListener('change', function() {
                const employeeId = parseInt(this.value);
                if (employeeId) {
                    window.location.href = '/employee/schedule/' + employeeId;
                }
            });
        }

        // Enable/disable time inputs based on checkbox
        const dayCheckboxes = document.querySelectorAll('.day-enabled');
        dayCheckboxes.forEach(function(checkbox) {
            checkbox.addEventListener('change', function() {
                const day = this.dataset.day;
                const row = this.closest('tr');
                const timeInputs = row.querySelectorAll('.booked-time-input');
                timeInputs.forEach(function(input) {
                    input.disabled = !checkbox.checked;
                    if (!checkbox.checked) {
                        input.value = '';
                    }
                });
            });
        });
    });

    /**
     * Load schedules from server
     */
    function loadSchedules() {
        if (!currentEmployeeId) {
            return;
        }

        if (schedulesList) {
            schedulesList.innerHTML = '<p class="booked-loading">Loading schedules...</p>';
        }

        const formData = new FormData();
        formData.append('employeeId', currentEmployeeId);
        formData.append(data.csrf.name, data.csrf.value);

        fetch(data.actionUrl.getSchedules, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            }
        })
        .then(function(response) {
            if (!response.ok) throw new Error('Server error: ' + response.status);
            return response.json();
        })
        .then(function(response) {
            if (response.success) {
                schedules = response.schedules || [];
                renderSchedules();
            } else {
                showError(response.message || 'Failed to load schedules');
            }
        })
        .catch(function(error) {
            console.error('Error loading schedules:', error);
            showError('Error loading schedules. Please try again.');
        });
    }

    /**
     * Render schedule list
     */
    function renderSchedules() {
        if (!schedulesList) {
            return;
        }

        if (schedules.length === 0) {
            schedulesList.innerHTML = '<p class="booked-loading">No schedules found. Click "Add Schedule" to create one.</p>';
            return;
        }

        let html = '';
        schedules.forEach(function(schedule) {
            const activeDays = BookedDateTime.getActiveDaysSummary(schedule.workingHours, data.days);
            const hoursSummary = BookedDateTime.getHoursSummary(schedule.workingHours);
            const dateRange = getDateRangeSummary(schedule.startDate, schedule.endDate);

            html += '<div class="booked-schedule-item">';
            html += '<div class="booked-schedule-item-header">';
            html += '<h3>' + escapeHtml(schedule.title || (strings.untitledSchedule || 'Untitled Schedule')) + '</h3>';
            html += '<div class="booked-schedule-item-actions">';
            html += '<button type="button" class="btn btn-small edit-schedule" data-id="' + escapeHtml(String(schedule.id)) + '">' + escapeHtml(strings.edit || 'Edit') + '</button>';
            html += '<button type="button" class="btn btn-small delete-schedule" data-id="' + escapeHtml(String(schedule.id)) + '">' + escapeHtml(strings.deleteLabel || 'Delete') + '</button>';
            html += '</div>';
            html += '</div>';
            html += '<div class="booked-schedule-item-meta">';
            html += '<div class="booked-schedule-item-meta-item">';
            html += '<strong>' + escapeHtml(strings.statusLabel || 'Status') + '</strong>';
            html += '<span>' + escapeHtml(schedule.enabled ? (strings.enabled || 'Enabled') : (strings.disabled || 'Disabled')) + '</span>';
            html += '</div>';
            html += '<div class="booked-schedule-item-meta-item">';
            html += '<strong>' + escapeHtml(strings.activeDaysLabel || 'Active Days') + '</strong>';
            html += '<span>' + escapeHtml(activeDays) + '</span>';
            html += '</div>';
            html += '<div class="booked-schedule-item-meta-item">';
            html += '<strong>' + escapeHtml(strings.hoursLabel || 'Hours') + '</strong>';
            html += '<span>' + escapeHtml(hoursSummary) + '</span>';
            html += '</div>';
            if (dateRange) {
                html += '<div class="booked-schedule-item-meta-item">';
                html += '<strong>' + escapeHtml(strings.dateRangeLabel || 'Date Range') + '</strong>';
                html += '<span>' + escapeHtml(dateRange) + '</span>';
                html += '</div>';
            }
            html += '</div>';
            html += '</div>';
        });

        schedulesList.innerHTML = html;

        // Attach event listeners
        schedulesList.querySelectorAll('.edit-schedule').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const scheduleId = parseInt(this.dataset.id);
                const schedule = schedules.find(function(s) {
                    return s.id === scheduleId;
                });
                if (schedule) {
                    showScheduleEditor(schedule);
                }
            });
        });

        schedulesList.querySelectorAll('.delete-schedule').forEach(function(btn) {
            btn.addEventListener('click', function() {
                const scheduleId = parseInt(this.dataset.id);
                if (confirm(strings.confirmDelete || 'Are you sure you want to delete this schedule?')) {
                    deleteSchedule(scheduleId);
                }
            });
        });
    }

    /**
     * Show schedule editor modal
     */
    function showScheduleEditor(schedule) {
        const isNew = !schedule;
        const modalTitle = document.getElementById('modal-title');
        const scheduleIdInput = document.getElementById('schedule-id');
        const titleInput = document.getElementById('schedule-title-input');
        const enabledInput = document.getElementById('schedule-enabled-input');
        const startDateInput = document.getElementById('schedule-start-date');
        const endDateInput = document.getElementById('schedule-end-date');

        if (modalTitle) {
            modalTitle.textContent = isNew ? (strings.addSchedule || 'Add Schedule') : (strings.editSchedule || 'Edit Schedule');
        }

        if (scheduleIdInput) {
            scheduleIdInput.value = schedule ? schedule.id : '';
        }

        if (titleInput) {
            titleInput.value = schedule ? (schedule.title || '') : '';
        }

        if (enabledInput) {
            enabledInput.checked = schedule ? (schedule.enabled !== false) : true;
        }

        if (startDateInput) {
            startDateInput.value = schedule ? (schedule.startDate || '') : '';
        }

        if (endDateInput) {
            endDateInput.value = schedule ? (schedule.endDate || '') : '';
        }

        // Populate working hours table
        const tbody = document.getElementById('working-hours-tbody');
        if (tbody) {
            const rows = tbody.querySelectorAll('tr');
            rows.forEach(function(row) {
                const day = parseInt(row.dataset.day);
                const workingHours = schedule ? (schedule.workingHours || {}) : {};
                const dayHours = workingHours[day] || workingHours[String(day)] || {
                    enabled: false,
                    start: '09:00',
                    end: '17:00',
                    breakStart: '',
                    breakEnd: ''
                };

                const checkbox = row.querySelector('.day-enabled');
                const startInput = row.querySelector('input[name*="[start]"]');
                const endInput = row.querySelector('input[name*="[end]"]');
                const breakStartInput = row.querySelector('input[name*="[breakStart]"]');
                const breakEndInput = row.querySelector('input[name*="[breakEnd]"]');

                if (checkbox) {
                    checkbox.checked = dayHours.enabled || false;
                }

                if (startInput) {
                    startInput.value = dayHours.start || '09:00';
                    startInput.disabled = !(dayHours.enabled || false);
                }

                if (endInput) {
                    endInput.value = dayHours.end || '17:00';
                    endInput.disabled = !(dayHours.enabled || false);
                }

                if (breakStartInput) {
                    breakStartInput.value = dayHours.breakStart || '';
                    breakStartInput.disabled = !(dayHours.enabled || false);
                }

                if (breakEndInput) {
                    breakEndInput.value = dayHours.breakEnd || '';
                    breakEndInput.disabled = !(dayHours.enabled || false);
                }
            });
        }

        // Show modal
        if (scheduleModal) {
            scheduleModal.style.display = 'block';
            document.body.style.overflow = 'hidden';
        }
    }

    /**
     * Close modal
     */
    function closeModal() {
        if (scheduleModal) {
            scheduleModal.style.display = 'none';
            document.body.style.overflow = '';
        }
        if (scheduleForm) {
            scheduleForm.reset();
            // Reset submit button state
            const submitBtn = scheduleForm.querySelector('button[type="submit"]');
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = strings.saveSchedule || 'Save Schedule';
            }
        }
    }

    /**
     * Handle schedule form submission
     */
    function handleScheduleSubmit(e) {
        e.preventDefault();

        if (!currentEmployeeId) {
            showError('Employee ID is required');
            return;
        }

        const formData = new FormData(scheduleForm);
        formData.append('employeeId', currentEmployeeId);
        formData.append(data.csrf.name, data.csrf.value);

        // Collect working hours
        const workingHours = {};
        const rows = document.querySelectorAll('#working-hours-tbody tr');
        rows.forEach(function(row) {
            const day = parseInt(row.dataset.day);
            const checkbox = row.querySelector('.day-enabled');
            const startInput = row.querySelector('input[name*="[start]"]');
            const endInput = row.querySelector('input[name*="[end]"]');
            const breakStartInput = row.querySelector('input[name*="[breakStart]"]');
            const breakEndInput = row.querySelector('input[name*="[breakEnd]"]');

            workingHours[day] = {
                enabled: checkbox ? checkbox.checked : false,
                start: startInput ? startInput.value : '09:00',
                end: endInput ? endInput.value : '17:00',
                breakStart: breakStartInput ? breakStartInput.value : null,
                breakEnd: breakEndInput ? breakEndInput.value : null
            };
        });

        // Remove individual working hours from form data and add as JSON
        const keysToDelete = [...formData.keys()].filter(k => k.startsWith('workingHours['));
        keysToDelete.forEach(k => formData.delete(k));
        formData.append('workingHours', JSON.stringify(workingHours));

        // Show loading state
        const submitBtn = scheduleForm.querySelector('button[type="submit"]');
        const originalText = submitBtn ? submitBtn.textContent : '';
        if (submitBtn) {
            submitBtn.disabled = true;
            submitBtn.textContent = 'Saving...';
        }

        fetch(data.actionUrl.saveSchedule, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            }
        })
        .then(function(response) {
            if (!response.ok) throw new Error('Server error: ' + response.status);
            return response.json();
        })
        .then(function(response) {
            // Always reset button state
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
            }

            if (response.success) {
                closeModal();
                loadSchedules();
                showSuccess(strings.scheduleSaved || 'Schedule saved successfully');
            } else {
                showError(response.message || 'Failed to save schedule');
            }
        })
        .catch(function(error) {
            console.error('Error saving schedule:', error);
            showError('Error saving schedule. Please try again.');
            // Always reset button state
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.textContent = originalText;
            }
        });
    }

    /**
     * Delete schedule
     */
    function deleteSchedule(scheduleId) {
        const formData = new FormData();
        formData.append('id', scheduleId);
        formData.append(data.csrf.name, data.csrf.value);

        fetch(data.actionUrl.deleteSchedule, {
            method: 'POST',
            body: formData,
            headers: {
                'X-Requested-With': 'XMLHttpRequest',
                'Accept': 'application/json',
            }
        })
        .then(function(response) {
            if (!response.ok) throw new Error('Server error: ' + response.status);
            return response.json();
        })
        .then(function(response) {
            if (response.success) {
                loadSchedules();
                showSuccess(strings.scheduleDeleted || 'Schedule deleted successfully');
            } else {
                showError(response.message || 'Failed to delete schedule');
            }
        })
        .catch(function(error) {
            console.error('Error deleting schedule:', error);
            showError('Error deleting schedule. Please try again.');
        });
    }

    /**
     * Helper: Get date range summary
     */
    function getDateRangeSummary(startDate, endDate) {
        if (!startDate && !endDate) {
            return null;
        }

        // Use shared date-time utility if available, otherwise fallback to simple format
        if (window.BookedDateTime && window.BookedDateTime.formatDateRange) {
            return window.BookedDateTime.formatDateRange(startDate, endDate);
        }
        
        // Fallback for when utility is not loaded
        const formatDate = (dateString) => {
            if (!dateString) return '';
            const date = new Date(dateString + 'T00:00:00');
            return date.toLocaleDateString();
        };
        
        if (startDate && endDate) {
            return formatDate(startDate) + ' - ' + formatDate(endDate);
        } else if (startDate) {
            return 'From ' + formatDate(startDate);
        } else if (endDate) {
            return 'Until ' + formatDate(endDate);
        }

        return null;
    }

    /**
     * Helper: Escape HTML
     */
    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    function showToast(message, type) {
        const toast = document.createElement('div');
        toast.className = 'booked-toast booked-toast--' + type;
        toast.textContent = message;
        document.body.appendChild(toast);
        requestAnimationFrame(() => toast.classList.add('booked-toast--visible'));
        setTimeout(() => {
            toast.classList.remove('booked-toast--visible');
            setTimeout(() => toast.remove(), 300);
        }, 3000);
    }

    function showSuccess(message) {
        showToast(message, 'success');
    }

    function showError(message) {
        showToast(message, 'error');
    }

})();
