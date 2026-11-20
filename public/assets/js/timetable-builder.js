(() => {
    const form = document.querySelector('[data-timetable-entry-form]');
    if (!form) {
        return;
    }

    const daySelect = form.querySelector('[data-entry-day]');
    const periodSelect = form.querySelector('[data-entry-period]');
    const teacherSelect = form.querySelector('[data-entry-teacher]');
    const subjectSelect = form.querySelector('[data-entry-subject]');
    const assignmentInput = form.querySelector('[data-entry-assignment]');
    const submitButton = form.querySelector('[data-entry-submit]');
    if (!daySelect || !periodSelect || !teacherSelect || !subjectSelect || !assignmentInput || !submitButton) {
        return;
    }

    const updatePeriods = () => {
        const selectedDay = daySelect.value;
        let selectedPeriodIsAvailable = false;
        Array.from(periodSelect.options).forEach((option) => {
            if (!option.value) {
                option.disabled = false;
                option.hidden = false;
                return;
            }
            const available = option.dataset.day === selectedDay;
            option.disabled = !available;
            option.hidden = !available;
            if (available && option.value === periodSelect.value) {
                selectedPeriodIsAvailable = true;
            }
        });
        if (!selectedPeriodIsAvailable) {
            periodSelect.value = '';
        }
        updateSubmitState();
    };

    const updateAssignment = () => {
        const selectedTeacher = teacherSelect.value;
        let selectedSubjectIsAvailable = false;
        Array.from(subjectSelect.options).forEach((option) => {
            if (!option.value) {
                option.disabled = false;
                option.hidden = false;
                return;
            }
            const available = option.dataset.teacherGroupId === selectedTeacher;
            option.disabled = !available;
            option.hidden = !available;
            if (available && option.value === subjectSelect.value) {
                selectedSubjectIsAvailable = true;
            }
        });
        if (!selectedSubjectIsAvailable) {
            subjectSelect.value = '';
        }
        const selectedOption = subjectSelect.selectedOptions[0];
        assignmentInput.value = selectedOption?.dataset.assignmentId || '';
        updateSubmitState();
    };

    function updateSubmitState() {
        submitButton.disabled = !daySelect.value
            || !periodSelect.value
            || !teacherSelect.value
            || !subjectSelect.value
            || !assignmentInput.value;
    }

    daySelect.addEventListener('change', updatePeriods);
    periodSelect.addEventListener('change', updateSubmitState);
    teacherSelect.addEventListener('change', updateAssignment);
    subjectSelect.addEventListener('change', updateAssignment);
    form.addEventListener('submit', (event) => {
        if (!assignmentInput.value) {
            event.preventDefault();
            subjectSelect.focus();
        }
    });

    updatePeriods();
    updateAssignment();
})();
