<!DOCTYPE html>
<html lang="es">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <meta name="apple-mobile-web-app-capable" content="yes">
    <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
    <meta name="theme-color" content="#2e7d32">
    <title>Centromex Volunteer Picker</title>
    <link rel="manifest" href="<?php echo CENTROMEX_PICKER_URL; ?>assets/manifest.json">
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        :root {
            --primary: #2e7d32;
            --primary-dark: #1b5e20;
            --primary-light: #4caf50;
            --secondary: #ff9800;
            --danger: #f44336;
            --success: #4caf50;
            --warning: #ff9800;
            --gray-100: #f5f5f5;
            --gray-200: #eeeeee;
            --gray-300: #e0e0e0;
            --gray-500: #9e9e9e;
            --gray-700: #616161;
            --gray-900: #212121;
            --shadow: 0 2px 8px rgba(0,0,0,0.1);
            --radius: 12px;
        }

        body {
            font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
            background: var(--gray-100);
            color: var(--gray-900);
            min-height: 100vh;
            padding-bottom: env(safe-area-inset-bottom);
        }

        /* Header */
        .header {
            background: var(--primary);
            color: white;
            padding: 16px;
            position: sticky;
            top: 0;
            z-index: 100;
            box-shadow: var(--shadow);
        }

        .header h1 {
            font-size: 1.25rem;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .header-back {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
            padding: 4px;
            margin-right: 8px;
            display: none;
        }

        .header-back.visible {
            display: inline-block;
        }

        .header-subtitle {
            font-size: 0.875rem;
            opacity: 0.9;
            margin-top: 4px;
        }

        /* Views */
        .view {
            display: none;
            padding: 16px;
            max-width: 600px;
            margin: 0 auto;
        }

        .view.active {
            display: block;
        }

        /* Login View */
        .login-card {
            background: white;
            border-radius: var(--radius);
            padding: 24px;
            box-shadow: var(--shadow);
            margin-top: 20px;
        }

        .login-card h2 {
            color: var(--primary);
            margin-bottom: 8px;
        }

        .login-card p {
            color: var(--gray-700);
            margin-bottom: 20px;
        }

        .form-group {
            margin-bottom: 16px;
        }

        .form-group label {
            display: block;
            font-weight: 500;
            margin-bottom: 6px;
            color: var(--gray-700);
        }

        .form-group input {
            width: 100%;
            padding: 14px;
            border: 2px solid var(--gray-300);
            border-radius: 8px;
            font-size: 1rem;
            transition: border-color 0.2s;
        }

        .form-group input:focus {
            outline: none;
            border-color: var(--primary);
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            padding: 14px 24px;
            border: none;
            border-radius: 8px;
            font-size: 1rem;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
            width: 100%;
        }

        .btn-primary {
            background: var(--primary);
            color: white;
        }

        .btn-primary:hover {
            background: var(--primary-dark);
        }

        .btn-primary:disabled {
            background: var(--gray-500);
            cursor: not-allowed;
        }

        .btn-secondary {
            background: var(--gray-200);
            color: var(--gray-900);
        }

        .btn-success {
            background: var(--success);
            color: white;
        }

        .btn-danger {
            background: var(--danger);
            color: white;
        }

        .btn-warning {
            background: var(--warning);
            color: white;
        }

        .btn-sm {
            padding: 8px 16px;
            font-size: 0.875rem;
        }

        /* Orders List */
        .orders-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
        }

        .orders-header h2 {
            font-size: 1.25rem;
        }

        .refresh-btn {
            background: none;
            border: none;
            color: var(--primary);
            font-size: 1.5rem;
            cursor: pointer;
            padding: 8px;
        }

        .order-card {
            background: white;
            border-radius: var(--radius);
            padding: 16px;
            margin-bottom: 12px;
            box-shadow: var(--shadow);
            cursor: pointer;
            transition: transform 0.2s;
        }

        .order-card:hover {
            transform: translateY(-2px);
        }

        .order-card.claimed {
            border-left: 4px solid var(--warning);
        }

        .order-card.complete {
            border-left: 4px solid var(--success);
        }

        .order-header {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            margin-bottom: 8px;
        }

        .order-number {
            font-size: 1.25rem;
            font-weight: 700;
            color: var(--primary);
        }

        .order-status {
            font-size: 0.75rem;
            padding: 4px 8px;
            border-radius: 4px;
            font-weight: 600;
            text-transform: uppercase;
        }

        .order-status.processing {
            background: #e3f2fd;
            color: #1976d2;
        }

        .order-status.picking {
            background: #fff3e0;
            color: #f57c00;
        }

        .order-status.picked {
            background: #e8f5e9;
            color: #388e3c;
        }

        .order-info {
            color: var(--gray-700);
            font-size: 0.9rem;
        }

        .order-info span {
            display: inline-block;
            margin-right: 16px;
        }

        .order-progress {
            margin-top: 12px;
        }

        .progress-bar {
            height: 6px;
            background: var(--gray-200);
            border-radius: 3px;
            overflow: hidden;
        }

        .progress-fill {
            height: 100%;
            background: var(--primary);
            transition: width 0.3s;
        }

        .progress-text {
            font-size: 0.8rem;
            color: var(--gray-500);
            margin-top: 4px;
        }

        /* Pick List View */
        .pick-order-summary {
            background: white;
            border-radius: var(--radius);
            padding: 16px;
            margin-bottom: 16px;
            box-shadow: var(--shadow);
        }

        .pick-order-summary h2 {
            color: var(--primary);
            margin-bottom: 8px;
        }

        .pick-stats {
            display: flex;
            gap: 16px;
            flex-wrap: wrap;
            margin-top: 12px;
        }

        .pick-stat {
            text-align: center;
            padding: 8px 12px;
            background: var(--gray-100);
            border-radius: 8px;
        }

        .pick-stat-value {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
        }

        .pick-stat-label {
            font-size: 0.75rem;
            color: var(--gray-500);
        }

        .pick-item {
            background: white;
            border-radius: var(--radius);
            padding: 16px;
            margin-bottom: 12px;
            box-shadow: var(--shadow);
            transition: all 0.2s;
        }

        .pick-item.picked {
            background: #e8f5e9;
            border-left: 4px solid var(--success);
        }

        .pick-item.substituted {
            background: #fff3e0;
            border-left: 4px solid var(--warning);
        }

        .pick-item.unavailable {
            background: #ffebee;
            border-left: 4px solid var(--danger);
        }

        .pick-item-header {
            display: flex;
            gap: 12px;
            align-items: flex-start;
        }

        .pick-item-image {
            width: 60px;
            height: 60px;
            border-radius: 8px;
            object-fit: cover;
            background: var(--gray-200);
        }

        .pick-item-details {
            flex: 1;
        }

        .pick-item-name {
            font-weight: 600;
            font-size: 1rem;
            margin-bottom: 4px;
        }

        .pick-item-translation {
            color: var(--gray-500);
            font-size: 0.875rem;
            font-style: italic;
            margin-bottom: 4px;
        }

        .pick-item-meta {
            font-size: 0.8rem;
            color: var(--gray-500);
        }

        .pick-item-meta span {
            display: inline-block;
            margin-right: 12px;
        }

        .pick-item-quantity {
            font-size: 1.5rem;
            font-weight: 700;
            color: var(--primary);
            min-width: 40px;
            text-align: center;
        }

        .pick-item-actions {
            display: flex;
            gap: 8px;
            margin-top: 12px;
            flex-wrap: wrap;
        }

        .pick-item-actions .btn {
            flex: 1;
            min-width: 100px;
        }

        .pick-complete-bar {
            position: fixed;
            bottom: 0;
            left: 0;
            right: 0;
            background: white;
            padding: 16px;
            box-shadow: 0 -2px 10px rgba(0,0,0,0.1);
            display: none;
        }

        .pick-complete-bar.visible {
            display: block;
        }

        /* Scanner Modal */
        .modal {
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0,0,0,0.8);
            z-index: 200;
            display: none;
            flex-direction: column;
        }

        .modal.active {
            display: flex;
        }

        .modal-header {
            background: var(--gray-900);
            color: white;
            padding: 16px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .modal-close {
            background: none;
            border: none;
            color: white;
            font-size: 1.5rem;
            cursor: pointer;
        }

        .scanner-container {
            flex: 1;
            position: relative;
            background: black;
        }

        .scanner-video {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }

        .scanner-overlay {
            position: absolute;
            top: 50%;
            left: 50%;
            transform: translate(-50%, -50%);
            width: 80%;
            max-width: 300px;
            aspect-ratio: 3/1;
            border: 3px solid var(--primary-light);
            border-radius: 8px;
            box-shadow: 0 0 0 9999px rgba(0,0,0,0.5);
        }

        .scanner-instructions {
            position: absolute;
            bottom: 20px;
            left: 0;
            right: 0;
            text-align: center;
            color: white;
            font-size: 0.9rem;
        }

        /* Photo Capture */
        .photo-preview {
            margin-top: 12px;
        }

        .photo-preview img {
            width: 100%;
            max-width: 200px;
            border-radius: 8px;
        }

        .photo-capture-container {
            flex: 1;
            display: flex;
            flex-direction: column;
        }

        .photo-capture-preview {
            flex: 1;
            position: relative;
            background: black;
        }

        .photo-capture-controls {
            padding: 20px;
            background: var(--gray-900);
            display: flex;
            justify-content: center;
            gap: 20px;
        }

        .capture-btn {
            width: 70px;
            height: 70px;
            border-radius: 50%;
            border: 4px solid white;
            background: transparent;
            cursor: pointer;
        }

        .capture-btn:active {
            background: rgba(255,255,255,0.3);
        }

        /* Notes Modal */
        .notes-modal-content {
            background: white;
            margin: auto 16px;
            border-radius: var(--radius);
            padding: 20px;
            max-height: 80vh;
            overflow-y: auto;
        }

        .notes-modal-content h3 {
            margin-bottom: 16px;
        }

        .notes-modal-content textarea {
            width: 100%;
            height: 120px;
            padding: 12px;
            border: 2px solid var(--gray-300);
            border-radius: 8px;
            font-size: 1rem;
            resize: vertical;
            margin-bottom: 16px;
        }

        /* Toast */
        .toast {
            position: fixed;
            bottom: 80px;
            left: 50%;
            transform: translateX(-50%);
            background: var(--gray-900);
            color: white;
            padding: 12px 24px;
            border-radius: 8px;
            z-index: 300;
            opacity: 0;
            transition: opacity 0.3s;
        }

        .toast.visible {
            opacity: 1;
        }

        .toast.success {
            background: var(--success);
        }

        .toast.error {
            background: var(--danger);
        }

        /* Loading */
        .loading {
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 40px;
        }

        .spinner {
            width: 40px;
            height: 40px;
            border: 4px solid var(--gray-200);
            border-top-color: var(--primary);
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }

        /* Empty state */
        .empty-state {
            text-align: center;
            padding: 40px 20px;
            color: var(--gray-500);
        }

        .empty-state-icon {
            font-size: 3rem;
            margin-bottom: 16px;
        }

        /* Helper classes */
        .mt-2 { margin-top: 8px; }
        .mt-4 { margin-top: 16px; }
        .text-center { text-align: center; }
        .text-success { color: var(--success); }
        .text-danger { color: var(--danger); }
        .text-warning { color: var(--warning); }

        /* Print/Screenshot friendly picked items */
        @media print {
            .header, .pick-item-actions, .pick-complete-bar {
                display: none !important;
            }
        }
    </style>
</head>
<body>
    <!-- Header -->
    <header class="header">
        <h1>
            <button class="header-back" id="backBtn" onclick="app.goBack()">&#8592;</button>
            <span id="headerTitle">Centromex Picker</span>
        </h1>
        <div class="header-subtitle" id="headerSubtitle">Sistema de recoleccion para voluntarios</div>
    </header>

    <!-- Login View -->
    <div class="view active" id="loginView">
        <div class="login-card">
            <h2>Bienvenido / Welcome</h2>
            <p>Ingrese su nombre para comenzar / Enter your name to start</p>

            <form id="loginForm" onsubmit="app.startSession(event)">
                <div class="form-group">
                    <label for="volunteerName">Nombre / Name *</label>
                    <input type="text" id="volunteerName" required placeholder="Tu nombre completo">
                </div>

                <div class="form-group">
                    <label for="volunteerPhone">Telefono / Phone (opcional)</label>
                    <input type="tel" id="volunteerPhone" placeholder="Para contacto">
                </div>

                <?php if (get_option('centromex_picker_access_code', '')): ?>
                <div class="form-group">
                    <label for="accessCode">Codigo de Acceso / Access Code *</label>
                    <input type="text" id="accessCode" required placeholder="Codigo proporcionado">
                </div>
                <?php endif; ?>

                <button type="submit" class="btn btn-primary" id="loginBtn">
                    Comenzar / Start
                </button>
            </form>
        </div>
    </div>

    <!-- Orders List View -->
    <div class="view" id="ordersView">
        <div class="orders-header">
            <h2>Pedidos / Orders</h2>
            <button class="refresh-btn" onclick="app.loadOrders()">&#8635;</button>
        </div>

        <div id="ordersList">
            <div class="loading">
                <div class="spinner"></div>
            </div>
        </div>
    </div>

    <!-- Pick List View -->
    <div class="view" id="pickView">
        <div class="pick-order-summary" id="pickOrderSummary">
            <!-- Filled by JS -->
        </div>

        <div id="pickList">
            <div class="loading">
                <div class="spinner"></div>
            </div>
        </div>

        <div class="pick-complete-bar" id="pickCompleteBar">
            <button class="btn btn-success" onclick="app.completeOrder()">
                Completar Pedido / Complete Order
            </button>
        </div>
    </div>

    <!-- Scanner Modal -->
    <div class="modal" id="scannerModal">
        <div class="modal-header">
            <span>Escanear Codigo / Scan Barcode</span>
            <button class="modal-close" onclick="app.closeScanner()">&times;</button>
        </div>
        <div class="scanner-container">
            <video class="scanner-video" id="scannerVideo" playsinline></video>
            <div class="scanner-overlay"></div>
            <div class="scanner-instructions">Apunte la camara al codigo de barras</div>
        </div>
    </div>

    <!-- Photo Capture Modal -->
    <div class="modal" id="photoModal">
        <div class="modal-header">
            <span>Tomar Foto / Take Photo</span>
            <button class="modal-close" onclick="app.closePhotoCapture()">&times;</button>
        </div>
        <div class="photo-capture-container">
            <div class="photo-capture-preview">
                <video class="scanner-video" id="photoVideo" playsinline></video>
            </div>
            <div class="photo-capture-controls">
                <button class="capture-btn" onclick="app.capturePhoto()"></button>
            </div>
        </div>
    </div>

    <!-- Notes Modal -->
    <div class="modal" id="notesModal" style="align-items: center;">
        <div class="notes-modal-content">
            <h3>Notas / Notes</h3>
            <textarea id="notesInput" placeholder="Agregar notas sobre este articulo..."></textarea>
            <button class="btn btn-primary" onclick="app.saveNotes()">Guardar / Save</button>
            <button class="btn btn-secondary mt-2" onclick="app.closeNotes()">Cancelar</button>
        </div>
    </div>

    <!-- Toast -->
    <div class="toast" id="toast"></div>

    <!-- Canvas for photo capture -->
    <canvas id="photoCanvas" style="display:none;"></canvas>

    <!-- BarcodeDetector Polyfill -->
    <script src="https://cdn.jsdelivr.net/npm/@aspect-ui/barcode-detector/dist/side-effects.min.js"></script>

    <script>
        const API_URL = '<?php echo esc_js($api_url); ?>';
        const INITIAL_ORDER_ID = <?php echo intval($order_id); ?>;

        const app = {
            sessionToken: null,
            volunteerName: null,
            currentView: 'login',
            currentOrder: null,
            currentPickId: null,
            orders: [],
            barcodeDetector: null,
            scannerStream: null,
            photoStream: null,

            init() {
                // Check for existing session
                const savedToken = localStorage.getItem('centromex_picker_session');
                const savedName = localStorage.getItem('centromex_picker_name');

                if (savedToken && savedName) {
                    this.validateSession(savedToken, savedName);
                }

                // Initialize barcode detector
                this.initBarcodeDetector();
            },

            async validateSession(token, name) {
                try {
                    const res = await fetch(`${API_URL}/session/validate`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ session_token: token })
                    });

                    const data = await res.json();

                    if (data.valid) {
                        this.sessionToken = token;
                        this.volunteerName = name;
                        this.showView('orders');
                        this.loadOrders();

                        if (INITIAL_ORDER_ID) {
                            this.openOrder(INITIAL_ORDER_ID);
                        }
                    }
                } catch (e) {
                    console.error('Session validation failed:', e);
                }
            },

            async startSession(event) {
                event.preventDefault();

                const name = document.getElementById('volunteerName').value.trim();
                const phone = document.getElementById('volunteerPhone').value.trim();
                const accessCodeInput = document.getElementById('accessCode');
                const accessCode = accessCodeInput ? accessCodeInput.value.trim() : '';

                if (!name) {
                    this.showToast('Por favor ingrese su nombre', 'error');
                    return;
                }

                const btn = document.getElementById('loginBtn');
                btn.disabled = true;
                btn.textContent = 'Iniciando...';

                try {
                    const res = await fetch(`${API_URL}/session/start`, {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({
                            volunteer_name: name,
                            volunteer_phone: phone,
                            access_code: accessCode
                        })
                    });

                    const data = await res.json();

                    if (data.success) {
                        this.sessionToken = data.session_token;
                        this.volunteerName = name;

                        localStorage.setItem('centromex_picker_session', data.session_token);
                        localStorage.setItem('centromex_picker_name', name);

                        this.showView('orders');
                        this.loadOrders();
                        this.showToast('Bienvenido, ' + name, 'success');
                    } else {
                        this.showToast(data.message || 'Error al iniciar sesion', 'error');
                    }
                } catch (e) {
                    console.error('Start session error:', e);
                    this.showToast('Error de conexion', 'error');
                } finally {
                    btn.disabled = false;
                    btn.textContent = 'Comenzar / Start';
                }
            },

            showView(view) {
                document.querySelectorAll('.view').forEach(v => v.classList.remove('active'));
                document.getElementById(view + 'View').classList.add('active');

                const backBtn = document.getElementById('backBtn');
                const headerTitle = document.getElementById('headerTitle');
                const headerSubtitle = document.getElementById('headerSubtitle');

                switch(view) {
                    case 'login':
                        backBtn.classList.remove('visible');
                        headerTitle.textContent = 'Centromex Picker';
                        headerSubtitle.textContent = 'Sistema de recoleccion para voluntarios';
                        break;
                    case 'orders':
                        backBtn.classList.remove('visible');
                        headerTitle.textContent = 'Pedidos';
                        headerSubtitle.textContent = this.volunteerName ? `Voluntario: ${this.volunteerName}` : '';
                        break;
                    case 'pick':
                        backBtn.classList.add('visible');
                        headerTitle.textContent = `Pedido #${this.currentOrder?.number || ''}`;
                        headerSubtitle.textContent = this.currentOrder?.customer?.name || '';
                        break;
                }

                this.currentView = view;
            },

            goBack() {
                if (this.currentView === 'pick') {
                    this.showView('orders');
                    this.loadOrders();
                }
            },

            async loadOrders() {
                const container = document.getElementById('ordersList');
                container.innerHTML = '<div class="loading"><div class="spinner"></div></div>';

                try {
                    const res = await this.apiGet('/orders');
                    this.orders = res;

                    if (res.length === 0) {
                        container.innerHTML = `
                            <div class="empty-state">
                                <div class="empty-state-icon">&#128722;</div>
                                <p>No hay pedidos pendientes</p>
                                <p>No pending orders</p>
                            </div>
                        `;
                        return;
                    }

                    container.innerHTML = res.map(order => this.renderOrderCard(order)).join('');
                } catch (e) {
                    console.error('Load orders error:', e);
                    container.innerHTML = '<div class="empty-state"><p>Error al cargar pedidos</p></div>';
                }
            },

            renderOrderCard(order) {
                const statusClass = order.pick_status.complete ? 'picked' :
                                   order.claimed_by ? 'claimed' : '';
                const statusLabel = order.pick_status.complete ? 'picked' :
                                   order.claimed_by ? 'picking' : 'processing';

                return `
                    <div class="order-card ${statusClass}" onclick="app.openOrder(${order.id})">
                        <div class="order-header">
                            <span class="order-number">#${order.number}</span>
                            <span class="order-status ${statusLabel}">${statusLabel}</span>
                        </div>
                        <div class="order-info">
                            <span>${order.customer_name}</span>
                            <span>${order.item_count} items</span>
                        </div>
                        ${order.claimed_by ? `<div class="order-info mt-2"><small>Reclamado por: ${order.claimed_by}</small></div>` : ''}
                        <div class="order-progress">
                            <div class="progress-bar">
                                <div class="progress-fill" style="width: ${order.pick_status.progress}%"></div>
                            </div>
                            <div class="progress-text">
                                ${order.pick_status.picked} de ${order.pick_status.total} recolectados
                            </div>
                        </div>
                    </div>
                `;
            },

            async openOrder(orderId) {
                this.showView('pick');

                const summaryContainer = document.getElementById('pickOrderSummary');
                const listContainer = document.getElementById('pickList');
                const completeBar = document.getElementById('pickCompleteBar');

                summaryContainer.innerHTML = '<div class="loading"><div class="spinner"></div></div>';
                listContainer.innerHTML = '';
                completeBar.classList.remove('visible');

                try {
                    // Claim the order
                    await this.apiPost(`/orders/${orderId}/claim`, {});

                    const order = await this.apiGet(`/orders/${orderId}`);
                    this.currentOrder = order;

                    // Update header
                    document.getElementById('headerTitle').textContent = `Pedido #${order.number}`;
                    document.getElementById('headerSubtitle').textContent = order.customer.name;

                    // Render summary
                    summaryContainer.innerHTML = `
                        <h2>Pedido #${order.number}</h2>
                        <div class="order-info">
                            <strong>${order.customer.name}</strong><br>
                            ${order.delivery.time ? `<span>Horario: ${order.delivery.time}</span><br>` : ''}
                            ${order.delivery.instructions ? `<span>Notas: ${order.delivery.instructions}</span>` : ''}
                        </div>
                        <div class="pick-stats">
                            <div class="pick-stat">
                                <div class="pick-stat-value">${order.pick_status.total}</div>
                                <div class="pick-stat-label">Total</div>
                            </div>
                            <div class="pick-stat">
                                <div class="pick-stat-value text-success">${order.pick_status.picked}</div>
                                <div class="pick-stat-label">Listos</div>
                            </div>
                            <div class="pick-stat">
                                <div class="pick-stat-value text-warning">${order.pick_status.substituted}</div>
                                <div class="pick-stat-label">Sustitutos</div>
                            </div>
                            <div class="pick-stat">
                                <div class="pick-stat-value text-danger">${order.pick_status.unavailable}</div>
                                <div class="pick-stat-label">No Disp.</div>
                            </div>
                        </div>
                    `;

                    // Render items
                    listContainer.innerHTML = order.items.map(item => this.renderPickItem(item)).join('');

                    // Show complete bar if all done
                    if (order.pick_status.complete) {
                        completeBar.classList.add('visible');
                    }

                } catch (e) {
                    console.error('Open order error:', e);
                    summaryContainer.innerHTML = '<div class="empty-state"><p>Error al cargar pedido</p></div>';
                }
            },

            renderPickItem(item) {
                const statusClass = item.status !== 'pending' ? item.status : '';
                const imageUrl = item.image || 'data:image/svg+xml,<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 100 100"><rect fill="%23eee" width="100" height="100"/><text x="50" y="55" text-anchor="middle" fill="%23999" font-size="14">No img</text></svg>';

                return `
                    <div class="pick-item ${statusClass}" id="pick-${item.pick_id}">
                        <div class="pick-item-header">
                            <img class="pick-item-image" src="${imageUrl}" alt="${item.name}">
                            <div class="pick-item-details">
                                <div class="pick-item-name">${item.name}</div>
                                ${item.name_translated ? `<div class="pick-item-translation">${item.name_translated}</div>` : ''}
                                <div class="pick-item-meta">
                                    ${item.sku ? `<span>SKU: ${item.sku}</span>` : ''}
                                    ${item.upc ? `<span>UPC: ${item.upc}</span>` : ''}
                                </div>
                            </div>
                            <div class="pick-item-quantity">x${item.quantity_ordered}</div>
                        </div>
                        ${item.photo_url ? `<div class="photo-preview"><img src="${item.photo_url}" alt="Foto"></div>` : ''}
                        ${item.notes ? `<div class="mt-2"><small><strong>Notas:</strong> ${item.notes}</small></div>` : ''}
                        ${item.status === 'pending' ? `
                        <div class="pick-item-actions">
                            <button class="btn btn-success btn-sm" onclick="app.markPicked(${item.pick_id})">
                                Listo
                            </button>
                            <button class="btn btn-secondary btn-sm" onclick="app.openScanner(${item.pick_id})">
                                Escanear
                            </button>
                            <button class="btn btn-secondary btn-sm" onclick="app.openPhotoCapture(${item.pick_id})">
                                Foto
                            </button>
                            <button class="btn btn-warning btn-sm" onclick="app.markSubstituted(${item.pick_id})">
                                Sustituto
                            </button>
                            <button class="btn btn-danger btn-sm" onclick="app.markUnavailable(${item.pick_id})">
                                No Disp.
                            </button>
                        </div>
                        ` : `
                        <div class="pick-item-actions">
                            <button class="btn btn-secondary btn-sm" onclick="app.undoPick(${item.pick_id})">
                                Deshacer
                            </button>
                            <button class="btn btn-secondary btn-sm" onclick="app.openNotes(${item.pick_id})">
                                Notas
                            </button>
                        </div>
                        `}
                    </div>
                `;
            },

            async markPicked(pickId) {
                await this.updatePick(pickId, { status: 'picked', quantity_picked: this.getPickQuantity(pickId) });
            },

            async markSubstituted(pickId) {
                await this.updatePick(pickId, { status: 'substituted' });
                this.openNotes(pickId);
            },

            async markUnavailable(pickId) {
                await this.updatePick(pickId, { status: 'unavailable' });
            },

            async undoPick(pickId) {
                await this.updatePick(pickId, { status: 'pending', quantity_picked: 0 });
            },

            getPickQuantity(pickId) {
                const item = this.currentOrder?.items?.find(i => i.pick_id === pickId);
                return item ? item.quantity_ordered : 1;
            },

            async updatePick(pickId, data) {
                try {
                    await this.apiPost(`/pick/${pickId}`, data);
                    await this.openOrder(this.currentOrder.id);
                    this.showToast('Actualizado', 'success');
                } catch (e) {
                    console.error('Update pick error:', e);
                    this.showToast('Error al actualizar', 'error');
                }
            },

            async completeOrder() {
                if (!this.currentOrder) return;

                try {
                    await this.apiPost(`/orders/${this.currentOrder.id}/complete`, {});
                    this.showToast('Pedido completado!', 'success');
                    this.showView('orders');
                    this.loadOrders();
                } catch (e) {
                    console.error('Complete order error:', e);
                    this.showToast(e.message || 'Error al completar', 'error');
                }
            },

            // Barcode Scanner
            async initBarcodeDetector() {
                if ('BarcodeDetector' in window) {
                    try {
                        this.barcodeDetector = new BarcodeDetector({
                            formats: ['ean_13', 'ean_8', 'upc_a', 'upc_e', 'code_128', 'code_39']
                        });
                    } catch (e) {
                        console.warn('BarcodeDetector not supported:', e);
                    }
                }
            },

            async openScanner(pickId) {
                this.currentPickId = pickId;
                const modal = document.getElementById('scannerModal');
                const video = document.getElementById('scannerVideo');

                modal.classList.add('active');

                try {
                    this.scannerStream = await navigator.mediaDevices.getUserMedia({
                        video: { facingMode: 'environment' }
                    });
                    video.srcObject = this.scannerStream;
                    await video.play();

                    this.startScanning(video);
                } catch (e) {
                    console.error('Camera error:', e);
                    this.showToast('No se pudo acceder a la camara', 'error');
                    this.closeScanner();
                }
            },

            startScanning(video) {
                if (!this.barcodeDetector) return;

                const scan = async () => {
                    if (!this.scannerStream) return;

                    try {
                        const barcodes = await this.barcodeDetector.detect(video);
                        if (barcodes.length > 0) {
                            const code = barcodes[0].rawValue;
                            this.handleBarcodeScan(code);
                            return;
                        }
                    } catch (e) {
                        // Ignore detection errors
                    }

                    requestAnimationFrame(scan);
                };

                scan();
            },

            async handleBarcodeScan(code) {
                this.closeScanner();
                this.showToast(`Codigo: ${code}`, 'success');

                try {
                    // Look up the barcode
                    const result = await this.apiGet(`/barcode/${code}`);

                    if (result.found) {
                        // Update the pick with scanned barcode
                        await this.updatePick(this.currentPickId, {
                            status: 'picked',
                            scanned_barcode: code,
                            quantity_picked: this.getPickQuantity(this.currentPickId)
                        });
                    } else {
                        // Still mark as picked but note the unknown barcode
                        await this.updatePick(this.currentPickId, {
                            status: 'picked',
                            scanned_barcode: code,
                            quantity_picked: this.getPickQuantity(this.currentPickId),
                            notes: `Codigo escaneado: ${code} (no encontrado en sistema)`
                        });
                    }
                } catch (e) {
                    console.error('Barcode lookup error:', e);
                }

                this.currentPickId = null;
            },

            closeScanner() {
                const modal = document.getElementById('scannerModal');
                const video = document.getElementById('scannerVideo');

                modal.classList.remove('active');

                if (this.scannerStream) {
                    this.scannerStream.getTracks().forEach(t => t.stop());
                    this.scannerStream = null;
                }
                video.srcObject = null;
            },

            // Photo Capture
            async openPhotoCapture(pickId) {
                this.currentPickId = pickId;
                const modal = document.getElementById('photoModal');
                const video = document.getElementById('photoVideo');

                modal.classList.add('active');

                try {
                    this.photoStream = await navigator.mediaDevices.getUserMedia({
                        video: { facingMode: 'environment' }
                    });
                    video.srcObject = this.photoStream;
                    await video.play();
                } catch (e) {
                    console.error('Camera error:', e);
                    this.showToast('No se pudo acceder a la camara', 'error');
                    this.closePhotoCapture();
                }
            },

            async capturePhoto() {
                const video = document.getElementById('photoVideo');
                const canvas = document.getElementById('photoCanvas');
                const ctx = canvas.getContext('2d');

                canvas.width = video.videoWidth;
                canvas.height = video.videoHeight;
                ctx.drawImage(video, 0, 0);

                const imageData = canvas.toDataURL('image/jpeg', 0.8);

                this.closePhotoCapture();
                this.showToast('Subiendo foto...', 'success');

                try {
                    await this.apiPost(`/pick/${this.currentPickId}/photo`, {
                        image: imageData
                    });

                    // Also mark as picked
                    await this.updatePick(this.currentPickId, {
                        status: 'picked',
                        quantity_picked: this.getPickQuantity(this.currentPickId)
                    });

                    this.showToast('Foto guardada', 'success');
                } catch (e) {
                    console.error('Photo upload error:', e);
                    this.showToast('Error al subir foto', 'error');
                }

                this.currentPickId = null;
            },

            closePhotoCapture() {
                const modal = document.getElementById('photoModal');
                const video = document.getElementById('photoVideo');

                modal.classList.remove('active');

                if (this.photoStream) {
                    this.photoStream.getTracks().forEach(t => t.stop());
                    this.photoStream = null;
                }
                video.srcObject = null;
            },

            // Notes
            openNotes(pickId) {
                this.currentPickId = pickId;
                const modal = document.getElementById('notesModal');
                const input = document.getElementById('notesInput');

                const item = this.currentOrder?.items?.find(i => i.pick_id === pickId);
                input.value = item?.notes || '';

                modal.classList.add('active');
                input.focus();
            },

            async saveNotes() {
                const notes = document.getElementById('notesInput').value.trim();

                try {
                    await this.apiPost(`/pick/${this.currentPickId}`, { notes });
                    await this.openOrder(this.currentOrder.id);
                    this.closeNotes();
                    this.showToast('Notas guardadas', 'success');
                } catch (e) {
                    console.error('Save notes error:', e);
                    this.showToast('Error al guardar', 'error');
                }
            },

            closeNotes() {
                const modal = document.getElementById('notesModal');
                modal.classList.remove('active');
                this.currentPickId = null;
            },

            // API Helpers
            async apiGet(endpoint) {
                const res = await fetch(API_URL + endpoint, {
                    headers: {
                        'X-Picker-Session': this.sessionToken
                    }
                });

                if (!res.ok) {
                    const err = await res.json();
                    throw new Error(err.message || 'API Error');
                }

                return res.json();
            },

            async apiPost(endpoint, data) {
                const res = await fetch(API_URL + endpoint, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Picker-Session': this.sessionToken
                    },
                    body: JSON.stringify(data)
                });

                if (!res.ok) {
                    const err = await res.json();
                    throw new Error(err.message || 'API Error');
                }

                return res.json();
            },

            showToast(message, type = '') {
                const toast = document.getElementById('toast');
                toast.textContent = message;
                toast.className = 'toast visible ' + type;

                setTimeout(() => {
                    toast.classList.remove('visible');
                }, 3000);
            }
        };

        // Initialize app
        document.addEventListener('DOMContentLoaded', () => app.init());
    </script>
</body>
</html>
