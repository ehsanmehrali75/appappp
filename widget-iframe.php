<?php
require_once 'inc/functions.php';

$projectId = $_GET['project'] ?? 'default';
$domain = $_GET['domain'] ?? '';

$db = getDB();
$project = null;

foreach ($db['projects'] as $p) {
    if ($p['id'] === $projectId) {
        $project = $p;
        break;
    }
}

if (!$project && !empty($db['projects'])) {
    $project = reset($db['projects']);
}

if (!$project) {
    die("پروژه چت آنلاین یافت نشد.");
}

// Extract Chat settings
$chat_settings = $project['settings']['chat'] ?? [];
$chat_enabled = $chat_settings['enabled'] ?? 1;

if (!$chat_enabled) {
    echo "<div style='font-family:sans-serif; text-align:center; padding: 20px; color: #64748b;'>چت آنلاین برای این وب‌سایت فعال نیست.</div>";
    exit;
}

// Check allowed domains
$allowed_domains_str = $chat_settings['widget_allowed_domains'] ?? '';
if (!empty($allowed_domains_str) && !empty($domain)) {
    $allowed_domains = array_filter(array_map('trim', explode(',', $allowed_domains_str)));
    $is_allowed = false;
    foreach ($allowed_domains as $d) {
        if (stripos($domain, $d) !== false) {
            $is_allowed = true;
            break;
        }
    }
    if (!$is_allowed) {
        echo "<div style='font-family:sans-serif; text-align:center; padding: 20px; color: #ef4444;'>دامنه مجاز برای استفاده از ابزارک گفتگو نمی‌باشد.</div>";
        exit;
    }
}

// Default values
$theme_color = $chat_settings['widget_theme_color'] ?? '#4f46e5';
$brand_name = $chat_settings['widget_brand_name'] ?? $project['name'] ?? 'پشتیبانی زنده';
$brand_logo = $chat_settings['widget_brand_logo'] ?? '';
$welcome_text = $chat_settings['widget_welcome_text'] ?? 'سلام! چطور می‌توانم شما را راهنمایی کنم؟';
$operator_name = $chat_settings['ai_operator_name'] ?? 'دستیار هوشمند';
$operator_avatar = $chat_settings['ai_avatar'] ?? '';
?>
<!DOCTYPE html>
<html lang="fa" dir="rtl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ابزارک گفتگوی ماارکشو</title>
    <!-- Tailwind CSS inside iframe -->
    <script src="assets/tailwindcss.js"></script>
    <link href="https://cdn.jsdelivr.net/gh/rastikerdar/vazirmatn@v33.003/Vazirmatn-font-face.css" rel="stylesheet" type="text/css" />
    <style>
        body, html, *, input, button, textarea {
            font-family: 'Vazirmatn', sans-serif !important;
        }
        body {
            background: transparent;
            margin: 0;
            padding: 0;
            overflow: hidden;
        }
        .theme-bg {
            background-color: <?php echo htmlspecialchars($theme_color); ?>;
        }
        .theme-text {
            color: <?php echo htmlspecialchars($theme_color); ?>;
        }
        .theme-border {
            border-color: <?php echo htmlspecialchars($theme_color); ?>;
        }
        .theme-ring:focus {
            --tw-ring-color: <?php echo htmlspecialchars($theme_color); ?>;
        }
        .glass-blur {
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(12px);
        }
        .scroller::-webkit-scrollbar {
            width: 4px;
        }
        .scroller::-webkit-scrollbar-track {
            background: transparent;
        }
        .scroller::-webkit-scrollbar-thumb {
            background: #cbd5e1;
            border-radius: 99px;
        }
        .bubble-user {
            background-color: <?php echo htmlspecialchars($theme_color); ?>;
            color: #ffffff;
            border-top-left-radius: 16px;
            border-bottom-left-radius: 16px;
            border-bottom-right-radius: 4px;
            border-top-right-radius: 16px;
        }
        .bubble-operator {
            background-color: #f1f5f9;
            color: #1e293b;
            border-top-left-radius: 16px;
            border-bottom-left-radius: 4px;
            border-bottom-right-radius: 16px;
            border-top-right-radius: 16px;
        }
        .scale-in {
            animation: scaleIn 0.25s cubic-bezier(0.4, 0, 0.2, 1) forwards;
        }
        @keyframes scaleIn {
            from { transform: scale(0.9); opacity: 0; }
            to { transform: scale(1); opacity: 1; }
        }
    </style>
</head>
<body class="h-full w-full select-none">

    <!-- ----------------------------------------------------------- -->
    <!-- VIEW A: COLLAPSED VIEW (Circular Floating Button) -->
    <!-- ----------------------------------------------------------- -->
    <div id="view-collapsed" class="fixed bottom-2 right-2 flex items-center justify-center w-14 h-14 rounded-full shadow-2xl cursor-pointer hover:scale-105 active:scale-95 transition-all duration-300 theme-bg text-white">
        <!-- Chat Bubble Icon -->
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.2" stroke="currentColor" class="w-7 h-7">
            <path stroke-linecap="round" stroke-linejoin="round" d="M7.5 8.25h9m-9 3H12m-9.75 1.51c0 1.6 1.123 2.994 2.707 3.227 1.129.166 2.27.293 3.423.379.35.026.67.21.865.501L12 21l2.755-4.133a1.14 1.14 0 01.865-.501 48.172 48.172 0 003.423-.379c1.584-.233 2.707-1.626 2.707-3.228V6.741c0-1.602-1.123-2.995-2.707-3.228A48.394 48.394 0 0012 3c-2.392 0-4.744.175-7.043.513C3.373 3.746 2.25 5.14 2.25 6.741v6.018z" />
        </svg>
    </div>

    <!-- ----------------------------------------------------------- -->
    <!-- VIEW B: EXPANDED VIEW (Chat Box Container) -->
    <!-- ----------------------------------------------------------- -->
    <div id="view-expanded" class="hidden fixed inset-0 flex flex-col glass-blur border border-slate-200/80 rounded-3xl overflow-hidden shadow-2xl scale-in">
        
        <!-- Chat Header -->
        <header class="flex items-center justify-between px-5 py-4 text-white theme-bg shadow-md relative">
            <div class="flex items-center gap-3">
                <!-- Brand Logo or Operator Avatar -->
                <div class="relative">
                    <?php if (!empty($brand_logo)): ?>
                        <img src="<?php echo htmlspecialchars($brand_logo); ?>" class="w-10 h-10 rounded-full object-cover border-2 border-white/40 shadow-sm" alt="Logo">
                    <?php else: ?>
                        <div class="w-10 h-10 rounded-full bg-white/20 border-2 border-white/40 shadow-sm flex items-center justify-center font-black text-sm text-white">
                            <?php echo mb_substr($brand_name, 0, 1); ?>
                        </div>
                    <?php endif; ?>
                    <!-- Green active pulse dot -->
                    <span class="absolute bottom-0 right-0 block h-2.5 w-2.5 rounded-full bg-emerald-400 ring-2 ring-white animate-pulse"></span>
                </div>
                
                <div class="text-right">
                    <h3 class="font-black text-xs"><?php echo htmlspecialchars($brand_name); ?></h3>
                    <p class="text-[9px] text-white/80 font-bold"><?php echo htmlspecialchars($operator_name); ?> (پاسخگوی زنده)</p>
                </div>
            </div>

            <!-- Close icon -->
            <button id="btn-close" class="p-1.5 rounded-xl hover:bg-white/10 active:scale-95 transition-all text-white">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2.5" stroke="currentColor" class="w-5 h-5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </header>

        <!-- Lead Capture Form View -->
        <div id="lead-form-panel" class="hidden flex-1 flex flex-col justify-center px-6 py-4 space-y-5 overflow-y-auto scroller">
            <div class="text-center space-y-1.5">
                <span class="text-3xl">👋</span>
                <h4 class="font-black text-sm text-slate-800">به پشتیبانی خوش آمدید!</h4>
                <p class="text-[10px] text-slate-500 font-extrabold leading-normal">جهت آغاز گفتگوی آنلاین و پاسخگویی سریع‌تر، لطفا مشخصات خود را تکمیل کنید:</p>
            </div>

            <form id="lead-form" class="space-y-4">
                <div>
                    <label class="block text-[10px] font-black text-slate-500 mb-1.5">نام و نام خانوادگی</label>
                    <input type="text" id="lead-name" required class="w-full px-3 py-2 text-xs border border-slate-200 rounded-xl focus:outline-none focus:ring-1 theme-ring" placeholder="مثال: علیرضا حسینی">
                </div>
                <div>
                    <label class="block text-[10px] font-black text-slate-500 mb-1.5">شماره موبایل</label>
                    <input type="tel" id="lead-phone" required class="w-full px-3 py-2 text-xs border border-slate-200 rounded-xl text-center focus:outline-none focus:ring-1 theme-ring" placeholder="مثال: 09123456789" style="direction: ltr;">
                </div>
                <div>
                    <label class="block text-[10px] font-black text-slate-500 mb-1.5">دپارتمان مربوطه</label>
                    <select id="lead-dept" class="w-full px-3 py-2 text-xs border border-slate-200 rounded-xl focus:outline-none focus:ring-1 theme-ring">
                        <option value="sales">واحد فروش و مشاور خرید</option>
                        <option value="technical">پشتیبانی فنی کالا</option>
                        <option value="management">واحد پیگیری و مدیریت</option>
                    </select>
                </div>

                <button type="submit" class="w-full py-3 text-xs font-black text-white rounded-xl shadow-lg shadow-indigo-100 theme-bg active:scale-95 transition-all">شروع گفتگوی زنده</button>
            </form>
        </div>

        <!-- Chat Workspace panel (Messages area) -->
        <div id="chat-workspace-panel" class="hidden flex-1 flex flex-col overflow-hidden bg-slate-50/50">
            <!-- Messages scroll area -->
            <div id="messages-container" class="flex-1 overflow-y-auto p-4 space-y-3.5 scroller">
                <!-- Welcome system bubble -->
                <div class="flex items-start gap-2.5 max-w-[85%]">
                    <?php if (!empty($operator_avatar)): ?>
                        <img src="<?php echo htmlspecialchars($operator_avatar); ?>" class="w-7 h-7 rounded-full object-cover border mt-0.5" alt="Avatar">
                    <?php else: ?>
                        <div class="w-7 h-7 rounded-full bg-slate-200 text-slate-700 flex items-center justify-center font-black text-[10px] mt-0.5">🤖</div>
                    <?php endif; ?>
                    <div class="bubble-operator px-4 py-2.5 text-xs font-medium shadow-sm leading-relaxed">
                        <?php echo htmlspecialchars($welcome_text); ?>
                    </div>
                </div>

                <!-- Live messages will insert here -->
            </div>

            <!-- Quick predefined messages pills -->
            <div id="predefined-container" class="flex-shrink-0 px-3 py-2 border-t border-slate-100 overflow-x-auto whitespace-nowrap scroller flex gap-1.5 bg-white">
                <!-- Dynamic predefined pills generated in script -->
            </div>

            <!-- Custom Message Send Input Bar -->
            <form id="message-send-form" class="flex items-center gap-2 px-4 py-3 bg-white border-t border-slate-150">
                <input type="text" id="message-text-input" autocomplete="off" class="flex-1 px-4 py-2.5 text-xs bg-slate-50/80 border border-slate-200 rounded-xl focus:outline-none focus:ring-1 theme-ring placeholder-slate-400 font-medium" placeholder="پیام خود را بنویسید...">
                <button type="submit" class="p-2.5 rounded-xl text-white theme-bg active:scale-95 transition-all hover:opacity-90">
                    <!-- Send vector icon -->
                    <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-4.5 h-4.5 transform rotate-180">
                        <path stroke-linecap="round" stroke-linejoin="round" d="M6 12L3.269 3.126A59.768 59.768 0 0121.485 12 59.77 59.77 0 013.27 20.876L5.999 12zm0 0h7.5" />
                    </svg>
                </button>
            </form>
        </div>
    </div>

    <!-- ----------------------------------------------------------- -->
    <!-- JAVASCRIPT STATE CONTROLLER -->
    <!-- ----------------------------------------------------------- -->
    <script>
        const projectId = '<?php echo htmlspecialchars($projectId); ?>';
        let visitorId = localStorage.getItem('maarksho_visitor_id') || '';
        let chatId = localStorage.getItem('maarksho_chat_id') || '';
        let isExpanded = false;
        let config = null;
        let messages = [];
        let pollTimer = null;

        // Dom Elements
        const viewCollapsed = document.getElementById('view-collapsed');
        const viewExpanded = document.getElementById('view-expanded');
        const btnClose = document.getElementById('btn-close');
        const leadFormPanel = document.getElementById('lead-form-panel');
        const chatWorkspacePanel = document.getElementById('chat-workspace-panel');
        const leadForm = document.getElementById('lead-form');
        const messageForm = document.getElementById('message-send-form');
        const messageInput = document.getElementById('message-text-input');
        const messagesContainer = document.getElementById('messages-container');
        const predefinedContainer = document.getElementById('predefined-container');

        // Toggle Expand and Collapse views
        function toggleWidget(expand) {
            isExpanded = expand;
            if (expand) {
                viewCollapsed.classList.add('hidden');
                viewExpanded.classList.remove('hidden');
                window.parent.postMessage({ type: 'maarksho-chat', action: 'expand' }, '*');
                scrollToBottom();
                initWidget();
                startPolling();
            } else {
                viewExpanded.classList.add('hidden');
                viewCollapsed.classList.remove('hidden');
                window.parent.postMessage({ type: 'maarksho-chat', action: 'collapse' }, '*');
                stopPolling();
            }
        }

        viewCollapsed.addEventListener('click', () => toggleWidget(true));
        btnClose.addEventListener('click', () => toggleWidget(false));

        // Fetch initialization data
        async function initWidget() {
            try {
                if (!visitorId) {
                    // Generate a temporary unique visitor ID
                    visitorId = 'vis_' + Math.random().toString(36).substring(2, 11);
                    localStorage.setItem('maarksho_visitor_id', visitorId);
                }

                const res = await fetch(`widget_api.php?project=${projectId}&action=init&visitor_id=${visitorId}`);
                const data = await res.json();
                
                if (data.ok) {
                    config = data.config;
                    
                    // Render predefined quick action messages
                    if (config.predefined_messages && config.predefined_messages.length > 0) {
                        predefinedContainer.innerHTML = '';
                        config.predefined_messages.forEach(item => {
                            const pill = document.createElement('button');
                            pill.className = 'px-3 py-1.5 text-[10px] font-black border rounded-full bg-slate-50 hover:bg-slate-100 transition-all text-slate-700';
                            pill.innerText = item.title;
                            pill.type = 'button';
                            pill.addEventListener('click', () => {
                                sendVisitorMessage(item.text);
                            });
                            predefinedContainer.appendChild(pill);
                        });
                        predefinedContainer.classList.remove('hidden');
                    } else {
                        predefinedContainer.classList.add('hidden');
                    }

                    if (data.conversation) {
                        chatId = data.conversation.id;
                        localStorage.setItem('maarksho_chat_id', chatId);
                        messages = data.conversation.messages || [];
                        renderMessages();
                        leadFormPanel.classList.add('hidden');
                        chatWorkspacePanel.classList.remove('hidden');
                    } else {
                        if (config.collect_leads) {
                            chatWorkspacePanel.classList.add('hidden');
                            leadFormPanel.classList.remove('hidden');
                        } else {
                            leadFormPanel.classList.add('hidden');
                            chatWorkspacePanel.classList.remove('hidden');
                        }
                    }
                }
            } catch (err) {
                console.error("Failed to initialize chat widget:", err);
            }
        }

        // Handle Lead Registration Form Submit
        leadForm.addEventListener('submit', async (e) => {
            e.preventDefault();
            const name = document.getElementById('lead-name').value;
            const phone = document.getElementById('lead-phone').value;
            const department = document.getElementById('lead-dept').value;

            try {
                const res = await fetch(`widget_api.php?project=${projectId}&action=save_lead`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ name, phone, department })
                });
                const data = await res.json();
                if (data.ok) {
                    chatId = data.chat_id;
                    localStorage.setItem('maarksho_chat_id', chatId);
                    leadFormPanel.classList.add('hidden');
                    chatWorkspacePanel.classList.remove('hidden');
                    messages = data.conversation.messages || [];
                    renderMessages();
                } else {
                    alert(data.error);
                }
            } catch (err) {
                console.error("Failed to submit lead registration:", err);
            }
        });

        // Send chat message
        async function sendVisitorMessage(text) {
            if (!text.trim() || !chatId) return;

            // Optimistic render
            const optimisticMsg = {
                id: 'opt_' + Date.now(),
                sender: 'user',
                text: text,
                time: new Date().toLocaleTimeString('fa-IR', { hour: '2-digit', minute: '2-digit' })
            };
            messages.push(optimisticMsg);
            renderMessages();
            messageInput.value = '';

            try {
                const res = await fetch(`widget_api.php?project=${projectId}&action=send_message`, {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ chat_id: chatId, text: text })
                });
                const data = await res.json();
                if (data.ok) {
                    messages = data.messages;
                    renderMessages();
                }
            } catch (err) {
                console.error("Failed to send message:", err);
            }
        }

        messageForm.addEventListener('submit', (e) => {
            e.preventDefault();
            sendVisitorMessage(messageInput.value);
        });

        // Render conversation messages bubbles
        function renderMessages() {
            // Keep welcome message
            let html = `
                <div class="flex items-start gap-2.5 max-w-[85%]">
                    ${config && config.operator_avatar 
                        ? `<img src="${config.operator_avatar}" class="w-7 h-7 rounded-full object-cover border mt-0.5" alt="Avatar">`
                        : `<div class="w-7 h-7 rounded-full bg-slate-200 text-slate-700 flex items-center justify-center font-black text-[10px] mt-0.5">🤖</div>`
                    }
                    <div class="bubble-operator px-4 py-2.5 text-xs font-medium shadow-sm leading-relaxed">
                        ${config ? config.welcome_text : 'سلام! خوش آمدید. چطور می‌توانم شما را راهنمایی کنم؟'}
                    </div>
                </div>
            `;

            messages.forEach(m => {
                if (m.sender === 'user') {
                    html += `
                        <div class="flex justify-end">
                            <div class="bubble-user max-w-[80%] px-4 py-2.5 text-xs font-medium shadow-sm leading-relaxed">
                                <p class="break-words">${escapeHTML(m.text)}</p>
                                <span class="block text-right text-[8px] text-white/70 mt-1 font-bold">${m.time}</span>
                            </div>
                        </div>
                    `;
                } else {
                    html += `
                        <div class="flex items-start gap-2.5 max-w-[85%]">
                            ${config && config.operator_avatar 
                                ? `<img src="${config.operator_avatar}" class="w-7 h-7 rounded-full object-cover border mt-0.5" alt="Avatar">`
                                : `<div class="w-7 h-7 rounded-full bg-slate-200 text-slate-700 flex items-center justify-center font-black text-[10px] mt-0.5">🤖</div>`
                            }
                            <div class="bubble-operator px-4 py-2.5 text-xs font-medium shadow-sm leading-relaxed">
                                <p class="break-words">${escapeHTML(m.text)}</p>
                                <span class="block text-left text-[8px] text-slate-400 mt-1 font-bold">${m.time}</span>
                            </div>
                        </div>
                    `;
                }
            });

            messagesContainer.innerHTML = html;
            scrollToBottom();
        }

        // Live Poll interval to fetch new operator responses
        function startPolling() {
            stopPolling();
            pollTimer = setInterval(async () => {
                if (!chatId) return;
                try {
                    const res = await fetch(`widget_api.php?project=${projectId}&action=get_messages&chat_id=${chatId}`);
                    const data = await res.json();
                    if (data.ok && JSON.stringify(data.messages) !== JSON.stringify(messages)) {
                        messages = data.messages;
                        renderMessages();
                    }
                } catch (err) {
                    console.error("Polling error:", err);
                }
            }, 4000);
        }

        function stopPolling() {
            if (pollTimer) {
                clearInterval(pollTimer);
                pollTimer = null;
            }
        }

        function scrollToBottom() {
            messagesContainer.scrollTop = messagesContainer.scrollHeight;
        }

        function escapeHTML(str) {
            return str.replace(/[&<>'"]/g, 
                tag => ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', "'": '&#39;', '"': '&quot;' }[tag] || tag)
            );
        }
    </script>
</body>
</html>
