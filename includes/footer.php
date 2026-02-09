<?php
/**
 * Footer Component with AI Chat
 */
?>

<footer class="app-footer">
    Elaborado por <a href="#">KINO GENIUS</a> &copy;
    <?= date('Y') ?>
</footer>

<!-- Chat Flotante con Gemini AI -->
<div id="aiChatWidget" class="ai-chat-widget">
    <button id="aiChatToggle" class="ai-chat-toggle" title="Asistente KINO">
        <svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" fill="none" viewBox="0 0 24 24"
            stroke="currentColor">
            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                d="M8 10h.01M12 10h.01M16 10h.01M9 16H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-5l-5 5v-5z" />
        </svg>
        <span class="ai-chat-badge" id="aiBadge" style="display: none;">!</span>
    </button>

    <div id="aiChatBox" class="ai-chat-box hidden">
        <div class="ai-chat-header">
            <div class="ai-chat-title">
                <span class="ai-avatar">🤖</span>
                <span>Asistente KINO</span>
            </div>
            <button id="aiChatClose" class="ai-chat-close">&times;</button>
        </div>

        <div id="aiChatMessages" class="ai-chat-messages">
            <div class="ai-message ai-message-bot">
                <div class="ai-message-content">
                    ¡Bienvenido a su mejor gestor de documentos! 🤖✨<br><br>
                    Soy su Asistente Inteligente KINO. Puedo ayudarle a:
                    <ul>
                        <li>🔍 <b>Rastrear códigos</b> en segundos.</li>
                        <li>📊 <b>Analizar documentos</b> (Manifiestos, Facturas).</li>
                        <li>🚀 <b>Explicar el uso</b> de la plataforma.</li>
                    </ul>
                    ¡Pregúnteme lo que necesite!
                </div>
            </div>
        </div>

        <div class="ai-chat-input-container">
            <input type="text" id="aiChatInput" class="ai-chat-input" placeholder="Escribe tu pregunta..."
                autocomplete="off">
            <button id="aiChatSend" class="ai-chat-send">
                <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" fill="none" viewBox="0 0 24 24"
                    stroke="currentColor">
                    <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2"
                        d="M12 19l9 2-9-18-9 18 9-2zm0 0v-8" />
                </svg>
            </button>
        </div>
    </div>
</div>

<style>
    /* Chat Flotante Styles */
    .ai-chat-widget {
        position: fixed;
        bottom: 1.5rem;
        right: 1.5rem;
        z-index: 9999;
    }

    .ai-chat-toggle {
        width: 56px;
        height: 56px;
        border-radius: 50%;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border: none;
        color: white;
        cursor: pointer;
        box-shadow: 0 4px 20px rgba(102, 126, 234, 0.4);
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.3s ease;
        position: relative;
    }

    .ai-chat-toggle:hover {
        transform: scale(1.1);
        box-shadow: 0 6px 25px rgba(102, 126, 234, 0.5);
    }

    .ai-chat-badge {
        position: absolute;
        top: -2px;
        right: -2px;
        width: 20px;
        height: 20px;
        background: #ef4444;
        border-radius: 50%;
        font-size: 12px;
        font-weight: bold;
        display: flex;
        align-items: center;
        justify-content: center;
    }

    .ai-chat-box {
        position: absolute;
        bottom: 70px;
        right: 0;
        width: 380px;
        max-width: calc(100vw - 2rem);
        background: var(--bg-primary);
        border: 1px solid var(--border-color);
        border-radius: var(--radius-lg);
        box-shadow: 0 10px 40px rgba(0, 0, 0, 0.3);
        overflow: hidden;
        transition: all 0.3s ease;
    }

    .ai-chat-box.hidden {
        opacity: 0;
        visibility: hidden;
        transform: translateY(20px);
    }

    .ai-chat-header {
        padding: 1rem;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .ai-chat-title {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        font-weight: 600;
    }

    .ai-avatar {
        font-size: 1.5rem;
    }

    .ai-chat-close {
        background: none;
        border: none;
        color: white;
        font-size: 1.5rem;
        cursor: pointer;
        padding: 0;
        line-height: 1;
    }

    .ai-chat-messages {
        height: 350px;
        overflow-y: auto;
        padding: 1rem;
        display: flex;
        flex-direction: column;
        gap: 1rem;
    }

    .ai-message {
        display: flex;
        gap: 0.5rem;
        max-width: 90%;
    }

    .ai-message-bot {
        align-self: flex-start;
    }

    .ai-message-user {
        align-self: flex-end;
        flex-direction: row-reverse;
    }

    .ai-message-content {
        padding: 0.75rem 1rem;
        border-radius: var(--radius-md);
        font-size: 0.875rem;
        line-height: 1.5;
    }

    .ai-message-bot .ai-message-content {
        background: var(--bg-secondary);
        color: var(--text-primary);
    }

    .ai-message-user .ai-message-content {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
    }

    .ai-message-content ul {
        margin: 0.5rem 0;
        padding-left: 1.25rem;
    }

    .ai-message-content li {
        margin: 0.25rem 0;
    }

    .ai-chat-input-container {
        display: flex;
        padding: 1rem;
        gap: 0.5rem;
        border-top: 1px solid var(--border-color);
        background: var(--bg-secondary);
    }

    .ai-chat-input {
        flex: 1;
        border: 1px solid var(--border-color);
        background: var(--bg-primary);
        padding: 0.75rem 1rem;
        border-radius: var(--radius-md);
        font-size: 0.875rem;
        color: var(--text-primary);
    }

    .ai-chat-input:focus {
        outline: none;
        border-color: var(--accent-primary);
    }

    .ai-chat-send {
        width: 44px;
        height: 44px;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        border: none;
        border-radius: var(--radius-md);
        color: white;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: transform 0.2s;
    }

    .ai-chat-send:hover {
        transform: scale(1.05);
    }

    .ai-chat-send:disabled {
        opacity: 0.5;
        cursor: not-allowed;
    }

    .ai-typing {
        display: flex;
        gap: 4px;
        padding: 0.75rem 1rem;
        background: var(--bg-secondary);
        border-radius: var(--radius-md);
        width: fit-content;
    }

    .ai-typing span {
        width: 8px;
        height: 8px;
        background: var(--text-muted);
        border-radius: 50%;
        animation: typing 1.4s infinite ease-in-out both;
    }

    .ai-typing span:nth-child(1) {
        animation-delay: -0.32s;
    }

    .ai-typing span:nth-child(2) {
        animation-delay: -0.16s;
    }

    @keyframes typing {

        0%,
        80%,
        100% {
            transform: scale(0);
        }

        40% {
            transform: scale(1);
        }
    }

    /* Links y códigos en chat */
    .chat-link {
        color: #667eea;
        text-decoration: underline;
        cursor: pointer;
    }

    .chat-code {
        background: rgba(102, 126, 234, 0.1);
        color: #667eea;
        padding: 0.125rem 0.375rem;
        border-radius: 4px;
        font-family: monospace;
        font-size: 0.8125rem;
    }

    /* Documentos relacionados en chat */
    .ai-doc-cards {
        display: flex;
        flex-direction: column;
        gap: 0.5rem;
        margin-top: 0.5rem;
    }

    .ai-doc-card {
        display: flex;
        align-items: center;
        gap: 0.5rem;
        padding: 0.5rem;
        background: var(--bg-tertiary);
        border-radius: var(--radius-sm);
        font-size: 0.8125rem;
        text-decoration: none;
        color: var(--text-primary);
        transition: background 0.2s;
    }

    .ai-doc-card:hover {
        background: var(--accent-primary);
        color: white;
    }
</style>

<script>
    // AI Chat Functionality
    (function () {
        const toggle = document.getElementById('aiChatToggle');
        const chatBox = document.getElementById('aiChatBox');
        const closeBtn = document.getElementById('aiChatClose');
        const input = document.getElementById('aiChatInput');
        const sendBtn = document.getElementById('aiChatSend');
        const messages = document.getElementById('aiChatMessages');
        const apiUrl = '<?= $baseUrl ?? "../../" ?>api.php';

        let isOpen = false;

        // Toggle chat
        toggle.addEventListener('click', () => {
            isOpen = !isOpen;
            chatBox.classList.toggle('hidden', !isOpen);
            if (isOpen) {
                input.focus();
            }
        });

        closeBtn.addEventListener('click', () => {
            isOpen = false;
            chatBox.classList.add('hidden');
        });

        // Send message
        async function sendMessage() {
            const question = input.value.trim();
            if (!question) return;

            // Add user message
            addMessage(question, 'user');
            input.value = '';

            // Show typing indicator
            const typingId = showTyping();
            sendBtn.disabled = true;

            try {
                const formData = new FormData();
                formData.append('action', 'smart_chat');
                formData.append('question', question);

                const response = await fetch(apiUrl, {
                    method: 'POST',
                    body: formData
                });

                const result = await response.json();

                // Remove typing indicator
                removeTyping(typingId);
                sendBtn.disabled = false;

                if (result.error) {
                    addMessage('Error: ' + result.error, 'bot');
                    return;
                }

                // Add bot response
                addMessage(result.response, 'bot', result.documents);

            } catch (error) {
                removeTyping(typingId);
                sendBtn.disabled = false;
                addMessage('Error de conexión. Por favor intenta de nuevo.', 'bot');
            }
        }

        function addMessage(content, type, documents = []) {
            const div = document.createElement('div');
            div.className = `ai-message ai-message-${type}`;

            let html = `<div class="ai-message-content">${content}</div>`;

            // Add document cards if present
            if (documents && documents.length > 0) {
                html += '<div class="ai-doc-cards">';
                documents.forEach(doc => {
                    html += `<a href="${baseUrl}/modules/resaltar/viewer.php?doc=${doc.id}" class="ai-doc-card">
                    📄 ${doc.tipo.toUpperCase()} #${doc.numero}
                </a>`;
                });
                html += '</div>';
            }

            div.innerHTML = html;
            messages.appendChild(div);
            messages.scrollTop = messages.scrollHeight;
        }

        function showTyping() {
            const id = 'typing-' + Date.now();
            const div = document.createElement('div');
            div.id = id;
            div.className = 'ai-message ai-message-bot';
            div.innerHTML = `<div class="ai-typing"><span></span><span></span><span></span></div>`;
            messages.appendChild(div);
            messages.scrollTop = messages.scrollHeight;
            return id;
        }

        function removeTyping(id) {
            const el = document.getElementById(id);
            if (el) el.remove();
        }

        // Event listeners
        sendBtn.addEventListener('click', sendMessage);
        input.addEventListener('keypress', (e) => {
            if (e.key === 'Enter') sendMessage();
        });
    })();
</script>

<!-- SweetAlert2 for confirmation modals -->
<script src="https://cdn.jsdelivr.net/npm/sweetalert2@11"></script>
<script>
    // Global confirmation handler for forms with data-confirm attribute
    document.addEventListener('DOMContentLoaded', function () {
        // Handle forms with data-confirm
        document.querySelectorAll('form[data-confirm]').forEach(form => {
            form.addEventListener('submit', async function (e) {
                e.preventDefault();

                const message = this.dataset.confirm || '¿Estás seguro de realizar esta acción?';
                const title = this.dataset.confirmTitle || '¿Confirmar acción?';
                const confirmText = this.dataset.confirmButton || 'Sí, continuar';
                const cancelText = this.dataset.cancelButton || 'Cancelar';
                const icon = this.dataset.confirmIcon || 'warning';

                const result = await Swal.fire({
                    title: title,
                    text: message,
                    icon: icon,
                    showCancelButton: true,
                    confirmButtonColor: '#3b82f6',
                    cancelButtonColor: '#64748b',
                    confirmButtonText: confirmText,
                    cancelButtonText: cancelText,
                    reverseButtons: true
                });

                if (result.isConfirmed) {
                    // Create hidden input to indicate confirmation was done
                    const confirmInput = document.createElement('input');
                    confirmInput.type = 'hidden';
                    confirmInput.name = '_confirmed';
                    confirmInput.value = '1';
                    this.appendChild(confirmInput);
                    this.submit();
                }
            });
        });

        // Handle buttons with data-confirm that trigger form submission
        document.querySelectorAll('button[data-confirm]').forEach(button => {
            button.addEventListener('click', async function (e) {
                if (this.form && !this.form.dataset.confirm) {
                    e.preventDefault();

                    const message = this.dataset.confirm || '¿Estás seguro?';
                    const title = this.dataset.confirmTitle || '¿Confirmar acción?';

                    const result = await Swal.fire({
                        title: title,
                        text: message,
                        icon: 'warning',
                        showCancelButton: true,
                        confirmButtonColor: '#3b82f6',
                        cancelButtonColor: '#64748b',
                        confirmButtonText: 'Sí, continuar',
                        cancelButtonText: 'Cancelar',
                        reverseButtons: true
                    });

                    if (result.isConfirmed) {
                        this.form.submit();
                    }
                }
            });
        });

        // Utility function for programmatic confirmations
        window.kinoConfirm = async function (options) {
            const defaults = {
                title: '¿Confirmar acción?',
                text: '¿Estás seguro de realizar esta acción?',
                icon: 'warning',
                confirmButtonText: 'Sí, continuar',
                cancelButtonText: 'Cancelar'
            };

            const config = { ...defaults, ...options };

            const result = await Swal.fire({
                title: config.title,
                text: config.text,
                icon: config.icon,
                showCancelButton: true,
                confirmButtonColor: config.dangerMode ? '#ef4444' : '#3b82f6',
                cancelButtonColor: '#64748b',
                confirmButtonText: config.confirmButtonText,
                cancelButtonText: config.cancelButtonText,
                reverseButtons: true
            });

            return result.isConfirmed;
        };

        // Success toast utility
        window.kinoToast = function (message, type = 'success') {
            const Toast = Swal.mixin({
                toast: true,
                position: 'top-end',
                showConfirmButton: false,
                timer: 3000,
                timerProgressBar: true
            });

            Toast.fire({
                icon: type,
                title: message
            });
        };
    });
</script>