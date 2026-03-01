// Валидация формы теста
document.addEventListener('DOMContentLoaded', function() {
    const testForm = document.getElementById('testForm');
    if (testForm) {
        testForm.addEventListener('submit', function(e) {
            const unanswered = [];
            const radioGroups = document.querySelectorAll('input[type="radio"]');
            const groups = {};
            
            // Группируем радио-кнопки по имени
            radioGroups.forEach(radio => {
                if (!groups[radio.name]) {
                    groups[radio.name] = [];
                }
                groups[radio.name].push(radio);
            });
            
            // Проверяем, отвечены ли все вопросы
            for (const groupName in groups) {
                const isAnswered = groups[groupName].some(radio => radio.checked);
                if (!isAnswered) {
                    unanswered.push(groupName.replace('answer_', ''));
                }
            }
            
            if (unanswered.length > 0) {
                e.preventDefault();
                alert(`Пожалуйста, ответьте на все вопросы!\nНе отвеченные вопросы: ${unanswered.join(', ')}`);
                
                // Прокрутка к первому неотвеченному вопросу
                const firstUnanswered = document.querySelector(`[name="answer_${unanswered[0]}"]`);
                if (firstUnanswered) {
                    firstUnanswered.closest('.card').scrollIntoView({
                        behavior: 'smooth',
                        block: 'center'
                    });
                }
            }
        });
    }
    
    // Анимация круга с результатом
    const resultCircle = document.querySelector('.result-circle');
    if (resultCircle) {
        const percent = resultCircle.getAttribute('data-percent');
        const circleValue = resultCircle.querySelector('.circle-value');
        
        // Анимация увеличения числа
        let currentPercent = 0;
        const interval = setInterval(() => {
            if (currentPercent >= percent) {
                clearInterval(interval);
            } else {
                currentPercent++;
                circleValue.textContent = currentPercent + '%';
            }
        }, 20);
    }
    
    // Подсветка выбранного ответа
    const radioInputs = document.querySelectorAll('input[type="radio"]');
    radioInputs.forEach(input => {
        input.addEventListener('change', function() {
            // Убираем подсветку со всех вариантов
            const allLabels = this.closest('.card-body').querySelectorAll('.form-check-label');
            allLabels.forEach(label => label.classList.remove('fw-bold', 'text-primary'));
            
            // Подсвечиваем выбранный вариант
            const selectedLabel = this.nextElementSibling;
            selectedLabel.classList.add('fw-bold', 'text-primary');
        });
    });
    
    // Таймер теста (опционально)
    if (window.location.pathname.includes('test.php')) {
        let timeLeft = 1800; // 30 минут в секундах
        const timerElement = document.createElement('div');
        timerElement.className = 'alert alert-warning position-fixed top-0 end-0 m-3';
        timerElement.style.zIndex = '1000';
        timerElement.innerHTML = `
            <div class="d-flex align-items-center">
                <i class="bi bi-clock fs-4 me-2"></i>
                <div>
                    <div class="fw-bold">Оставшееся время:</div>
                    <div id="timer">30:00</div>
                </div>
            </div>
        `;
        document.body.appendChild(timerElement);
        
        const timerDisplay = document.getElementById('timer');
        const timerInterval = setInterval(() => {
            if (timeLeft <= 0) {
                clearInterval(timerInterval);
                alert('Время вышло! Тест будет автоматически отправлен.');
                document.getElementById('testForm').submit();
            } else {
                timeLeft--;
                const minutes = Math.floor(timeLeft / 60);
                const seconds = timeLeft % 60;
                timerDisplay.textContent = `${minutes.toString().padStart(2, '0')}:${seconds.toString().padStart(2, '0')}`;
                
                // Изменение цвета при малом времени
                if (timeLeft < 300) { // 5 минут
                    timerElement.classList.remove('alert-warning');
                    timerElement.classList.add('alert-danger');
                }
            }
        }, 1000);
    }
});
