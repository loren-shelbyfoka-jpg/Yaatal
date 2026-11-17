<?php
declare(strict_types=1);

date_default_timezone_set('Africa/Dakar');
session_set_cookie_params([
    'httponly' => true,
    'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
    'samesite' => 'Lax',
    'path' => '/',
]);
session_start();

function e(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function go(string $destination): never
{
    header('Location: index.php?page=' . rawurlencode($destination));
    exit;
}

function flash(string $message, string $kind = 'success'): void
{
    $_SESSION['flash'] = ['message' => $message, 'kind' => $kind];
}

function csrfToken(): string
{
    if (!isset($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

function requireCsrf(): void
{
    $provided = $_POST['_csrf'] ?? '';
    if (!is_string($provided) || !hash_equals(csrfToken(), $provided)) {
        http_response_code(400);
        exit('Le formulaire a expiré. Rechargez la page puis réessayez.');
    }
}

function openDatabase(): PDO
{
    $directory = getenv('YAATAL_DATA_DIR');
    if ($directory === false || $directory === '') {
        $directory = dirname(__DIR__) . DIRECTORY_SEPARATOR . '.yaatal-storage';
    }
    if (!is_dir($directory) && !mkdir($directory, 0775, true) && !is_dir($directory)) {
        throw new RuntimeException('Le dossier de données YAATAL ne peut pas être créé.');
    }

    $db = new PDO('sqlite:' . $directory . DIRECTORY_SEPARATOR . 'yaatal.sqlite');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    $db->exec('PRAGMA foreign_keys = ON');
    $db->exec('PRAGMA journal_mode = WAL');
    $db->exec(<<<'SQL'
        CREATE TABLE IF NOT EXISTS producers (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            phone TEXT NOT NULL,
            village TEXT NOT NULL,
            zone_group TEXT NOT NULL DEFAULT 'Niayes',
            climate TEXT NOT NULL DEFAULT 'normal',
            active INTEGER NOT NULL DEFAULT 0
        );
        CREATE TABLE IF NOT EXISTS users (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            name TEXT NOT NULL,
            email TEXT NOT NULL UNIQUE,
            password_hash TEXT NOT NULL,
            role TEXT NOT NULL CHECK (role IN ('client', 'producer', 'admin')),
            phone TEXT NOT NULL DEFAULT '',
            address TEXT NOT NULL DEFAULT '',
            business_type TEXT NOT NULL DEFAULT 'Commerce',
            producer_id INTEGER REFERENCES producers(id)
        );
        CREATE TABLE IF NOT EXISTS products (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            producer_id INTEGER NOT NULL REFERENCES producers(id),
            name TEXT NOT NULL,
            category TEXT NOT NULL,
            unit TEXT NOT NULL DEFAULT 'kg',
            producer_price INTEGER NOT NULL,
            commission INTEGER NOT NULL,
            transport_fee INTEGER NOT NULL,
            available_quantity REAL NOT NULL DEFAULT 0,
            image_url TEXT NOT NULL DEFAULT '',
            is_available INTEGER NOT NULL DEFAULT 1
        );
        CREATE TABLE IF NOT EXISTS deliveries (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            label TEXT NOT NULL,
            region TEXT NOT NULL,
            delivery_at TEXT NOT NULL,
            cutoff_at TEXT NOT NULL,
            target_kg INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'open'
        );
        CREATE TABLE IF NOT EXISTS orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            reference TEXT NOT NULL UNIQUE,
            user_id INTEGER NOT NULL REFERENCES users(id),
            delivery_id INTEGER NOT NULL REFERENCES deliveries(id),
            delivery_address TEXT NOT NULL,
            total INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'pending',
            created_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS order_items (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id INTEGER NOT NULL REFERENCES orders(id),
            product_id INTEGER REFERENCES products(id),
            product_name TEXT NOT NULL,
            quantity REAL NOT NULL,
            unit TEXT NOT NULL,
            producer_price INTEGER NOT NULL,
            commission INTEGER NOT NULL,
            transport_fee INTEGER NOT NULL,
            unit_price INTEGER NOT NULL
        );
        CREATE TABLE IF NOT EXISTS producer_profiles (
            user_id INTEGER PRIMARY KEY REFERENCES users(id),
            farm_size REAL NOT NULL,
            irrigation TEXT NOT NULL,
            updated_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS yield_history (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            user_id INTEGER NOT NULL REFERENCES users(id),
            crop TEXT NOT NULL,
            year INTEGER NOT NULL,
            season TEXT NOT NULL,
            yield_t_ha REAL NOT NULL
        );
        CREATE TABLE IF NOT EXISTS predictions (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            season TEXT NOT NULL,
            zone_group TEXT NOT NULL,
            climate TEXT NOT NULL,
            crop TEXT NOT NULL,
            sowing_window TEXT NOT NULL,
            suitability TEXT NOT NULL,
            expected_yield REAL NOT NULL,
            notes TEXT NOT NULL,
            UNIQUE (season, zone_group, climate, crop)
        );
        CREATE TABLE IF NOT EXISTS supplier_orders (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            producer_id INTEGER NOT NULL REFERENCES producers(id),
            product_id INTEGER NOT NULL REFERENCES products(id),
            delivery_id INTEGER NOT NULL REFERENCES deliveries(id),
            quantity REAL NOT NULL,
            producer_unit_price INTEGER NOT NULL,
            status TEXT NOT NULL DEFAULT 'à planifier',
            created_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS quality_visits (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            supplier_order_id INTEGER NOT NULL REFERENCES supplier_orders(id),
            scheduled_at TEXT NOT NULL,
            notes TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT 'planifiée',
            created_at TEXT NOT NULL
        );
        CREATE TABLE IF NOT EXISTS feedback (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            order_id INTEGER NOT NULL UNIQUE REFERENCES orders(id),
            user_id INTEGER NOT NULL REFERENCES users(id),
            rating INTEGER NOT NULL,
            issue TEXT NOT NULL DEFAULT '',
            message TEXT NOT NULL,
            created_at TEXT NOT NULL
        );
        SQL);

    seedDatabase($db);
    return $db;
}

function seedDatabase(PDO $db): void
{
    if ((int) $db->query('SELECT COUNT(*) FROM users')->fetchColumn() > 0) {
        return;
    }

    $production = getenv('APP_ENV') === 'production';
    $adminEmail = getenv('YAATAL_ADMIN_EMAIL');
    $adminPassword = getenv('YAATAL_ADMIN_PASSWORD');
    if ($production && (
        $adminEmail === false
        || !filter_var($adminEmail, FILTER_VALIDATE_EMAIL)
        || $adminPassword === false
        || strlen($adminPassword) < 12
    )) {
        throw new RuntimeException(
            'En production, configurez YAATAL_ADMIN_EMAIL et YAATAL_ADMIN_PASSWORD (12 caractères minimum) avant le premier démarrage.'
        );
    }

    $db->beginTransaction();
    $userInsert = $db->prepare('INSERT INTO users (name, email, password_hash, role, phone, address, business_type, producer_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
    if ($production) {
        $userInsert->execute([
            'Équipe YAATAL',
            $adminEmail,
            password_hash($adminPassword, PASSWORD_DEFAULT),
            'admin',
            '',
            '',
            'YAATAL',
            null,
        ]);
    } else {
        $producerInsert = $db->prepare('INSERT INTO producers (name, phone, village, zone_group, climate, active) VALUES (?, ?, ?, ?, ?, 1)');
        $producers = [
            ['Awa Ndiaye', '+221 77 310 40 51', 'Pout, Thiès', 'Niayes', 'normal'],
            ['Mamadou Fall', '+221 76 211 08 32', 'Notto, Thiès', 'Niayes', 'sec'],
            ['Khady Diop', '+221 70 455 19 26', 'Gandon, Saint-Louis', 'Vallée du fleuve', 'normal'],
            ['Ousmane Ba', '+221 78 504 92 11', 'Fandène, Thiès', 'Niayes', 'humide'],
        ];
        $producerIds = [];
        foreach ($producers as $producer) {
            $producerInsert->execute($producer);
            $producerIds[] = (int) $db->lastInsertId();
        }

        $productInsert = $db->prepare(
            'INSERT INTO products (producer_id, name, category, unit, producer_price, commission, transport_fee, available_quantity, image_url, is_available)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
        );
        $products = [
            [$producerIds[0], 'Tomates de plein champ', 'Tomate', 'kg', 650, 90, 75, 1200, 'https://images.unsplash.com/photo-1592924357228-91a4daadcfea?w=900&auto=format&fit=crop&q=82'],
            [$producerIds[1], 'Carottes fraîchement récoltées', 'Carotte', 'kg', 540, 70, 65, 900, 'https://images.unsplash.com/photo-1445282768818-728615cc910a?w=900&auto=format&fit=crop&q=82'],
            [$producerIds[2], 'Oignons jaunes locaux', 'Oignon', 'kg', 470, 65, 80, 1600, 'https://images.unsplash.com/photo-1508747703725-719777637510?w=900&auto=format&fit=crop&q=82'],
            [$producerIds[3], 'Laitue croquante', 'Laitue', 'pièce', 390, 55, 45, 600, 'https://images.unsplash.com/photo-1540420773420-3366772f4999?w=900&auto=format&fit=crop&q=82'],
            [$producerIds[0], 'Pommes de terre de saison', 'Pomme de terre', 'kg', 500, 60, 70, 1100, 'https://images.unsplash.com/photo-1518977676601-b53f82aba655?w=900&auto=format&fit=crop&q=82'],
        ];
        foreach ($products as $product) {
            $productInsert->execute($product);
        }

        $password = password_hash('Yaatal2026!', PASSWORD_DEFAULT);
        $userInsert->execute(['Équipe YAATAL', 'admin@yaatal.local', $password, 'admin', '', '', 'YAATAL', null]);
        $userInsert->execute(['Restaurant Démo', 'client@yaatal.local', $password, 'client', '+221 77 000 00 00', 'Thiès, Sénégal', 'Restaurant', null]);
        $userInsert->execute(['Awa Ndiaye', 'producteur@yaatal.local', $password, 'producer', $producers[0][1], '', 'Producteur', $producerIds[0]]);
        $producerUserId = (int) $db->lastInsertId();
        $db->prepare('INSERT INTO producer_profiles (user_id, farm_size, irrigation, updated_at) VALUES (?, 1.5, ?, ?)')
            ->execute([$producerUserId, 'Goutte-à-goutte', date('Y-m-d H:i:s')]);

        $deliveryInsert = $db->prepare('INSERT INTO deliveries (label, region, delivery_at, cutoff_at, target_kg, status) VALUES (?, ?, ?, ?, ?, ?)');
        for ($i = 1; $i <= 3; $i++) {
            $deliveryDate = new DateTimeImmutable('+' . (10 + 7 * ($i - 1)) . ' days');
            $cutoffDate = $deliveryDate->modify('-3 days')->setTime(18, 0);
            $deliveryInsert->execute([
                'Ravitaillement ' . $deliveryDate->format('d/m'),
                'Thiès et environs',
                $deliveryDate->format('Y-m-d H:i:s'),
                $cutoffDate->format('Y-m-d H:i:s'),
                1000,
                'open',
            ]);
        }
    }

    $seasons = ['Saison fraîche', 'Saison chaude', 'Hivernage'];
    $zones = ['Niayes', 'Vallée du fleuve'];
    $climates = ['sec', 'normal', 'humide'];
    $crops = [
        'Tomate' => ['windows' => ['Saison fraîche' => 'Octobre – janvier', 'Saison chaude' => 'Février – avril', 'Hivernage' => 'Mai – juillet'], 'yield' => [16, 11, 8]],
        'Carotte' => ['windows' => ['Saison fraîche' => 'Novembre – février', 'Saison chaude' => 'Mars – mai', 'Hivernage' => 'À adapter à la parcelle'], 'yield' => [12, 6, 4]],
        'Oignon' => ['windows' => ['Saison fraîche' => 'Octobre – décembre', 'Saison chaude' => 'Janvier – mars', 'Hivernage' => 'À anticiper'], 'yield' => [15, 8, 5]],
        'Laitue' => ['windows' => ['Saison fraîche' => 'Octobre – février', 'Saison chaude' => 'Culture sous suivi', 'Hivernage' => 'Protéger les planches'], 'yield' => [9, 5, 3]],
    ];
    $predictionInsert = $db->prepare(
        'INSERT INTO predictions (season, zone_group, climate, crop, sowing_window, suitability, expected_yield, notes)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)'
    );
    foreach ($seasons as $seasonIndex => $season) {
        foreach ($zones as $zone) {
            foreach ($climates as $climate) {
                foreach ($crops as $crop => $details) {
                    $zoneFactor = $zone === 'Vallée du fleuve' && in_array($crop, ['Tomate', 'Oignon'], true) ? 1.08 : 1.0;
                    $climateFactor = match ($climate) {
                        'sec' => 0.78,
                        'humide' => in_array($crop, ['Laitue', 'Carotte'], true) ? 0.86 : 0.9,
                        default => 1.0,
                    };
                    $yield = round($details['yield'][$seasonIndex] * $zoneFactor * $climateFactor, 1);
                    $suitability = $yield >= 10 ? 'Potentiel intéressant' : ($yield >= 6 ? 'À surveiller' : 'À adapter');
                    $notes = $climate === 'sec'
                        ? 'Prévoir une irrigation maîtrisée et surveiller la disponibilité en eau.'
                        : ($climate === 'humide'
                            ? 'Surveiller le drainage et la pression des maladies.'
                            : 'Scénario de référence ; ajuster selon la parcelle et les observations locales.');
                    $predictionInsert->execute([$season, $zone, $climate, $crop, $details['windows'][$season], $suitability, $yield, $notes]);
                }
            }
        }
    }
    $db->commit();
}

function seasonFor(DateTimeImmutable $date): string
{
    $month = (int) $date->format('n');
    if (in_array($month, [11, 12, 1, 2], true)) {
        return 'Saison fraîche';
    }
    if (in_array($month, [3, 4, 5, 6], true)) {
        return 'Saison chaude';
    }
    return 'Hivernage';
}

function roleHome(string $role): string
{
    return match ($role) {
        'admin' => 'technique',
        'producer' => 'producteur',
        default => 'client',
    };
}

function requireRole(?array $user, string $role): void
{
    if ($user === null || $user['role'] !== $role) {
        http_response_code(403);
        exit('Accès refusé. Connectez-vous avec un compte autorisé.');
    }
}

$db = openDatabase();
$errors = [];
$currentUser = null;
if (isset($_SESSION['user_id'])) {
    $statement = $db->prepare('SELECT * FROM users WHERE id = ?');
    $statement->execute([(int) $_SESSION['user_id']]);
    $currentUser = $statement->fetch() ?: null;
    if ($currentUser === null) {
        unset($_SESSION['user_id']);
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    requireCsrf();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'login') {
        $email = filter_var(trim((string) ($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        $password = (string) ($_POST['password'] ?? '');
        $statement = $db->prepare('SELECT * FROM users WHERE email = ?');
        $statement->execute([$email ?: '']);
        $account = $statement->fetch();
        if ($account && password_verify($password, $account['password_hash'])) {
            session_regenerate_id(true);
            $_SESSION['user_id'] = (int) $account['id'];
            flash('Heureux de vous retrouver, ' . $account['name'] . '.');
            go(roleHome($account['role']));
        }
        $errors[] = 'Adresse e-mail ou mot de passe incorrect.';
    } elseif ($action === 'register') {
        $name = trim((string) ($_POST['name'] ?? ''));
        $email = filter_var(trim((string) ($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        $password = (string) ($_POST['password'] ?? '');
        $role = (string) ($_POST['role'] ?? '');
        $phone = trim((string) ($_POST['phone'] ?? ''));
        $businessType = $role === 'client' ? (string) ($_POST['business_type'] ?? '') : 'Producteur';
        if ($name === '' || !$email || strlen($password) < 10 || !in_array($role, ['client', 'producer'], true)) {
            $errors[] = 'Renseignez les champs demandés et choisissez un mot de passe de 10 caractères minimum.';
        } elseif ($role === 'client' && !in_array($businessType, ['Petit commerce', 'Commerce moyen', 'Grand commerce', 'Restaurant', 'Supermarché', 'Marché', 'Autre'], true)) {
            $errors[] = 'Choisissez le type de votre établissement.';
        } else {
            $statement = $db->prepare('SELECT id FROM users WHERE email = ?');
            $statement->execute([$email]);
            if ($statement->fetch()) {
                $errors[] = 'Cette adresse e-mail possède déjà un compte.';
            } else {
                $db->beginTransaction();
                $producerId = null;
                if ($role === 'producer') {
                    $village = trim((string) ($_POST['village'] ?? ''));
                    $zoneGroup = (string) ($_POST['zone_group'] ?? '');
                    if ($phone === '' || $village === '' || !in_array($zoneGroup, ['Niayes', 'Vallée du fleuve'], true)) {
                        $db->rollBack();
                        $errors[] = 'Pour créer un espace producteur, renseignez le téléphone, le village et la zone.';
                    } else {
                        $statement = $db->prepare('INSERT INTO producers (name, phone, village, zone_group, active) VALUES (?, ?, ?, ?, 0)');
                        $statement->execute([$name, $phone, $village, $zoneGroup]);
                        $producerId = (int) $db->lastInsertId();
                    }
                }
                if (!$errors) {
                    $statement = $db->prepare('INSERT INTO users (name, email, password_hash, role, phone, address, business_type, producer_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
                    $statement->execute([
                        $name,
                        $email,
                        password_hash($password, PASSWORD_DEFAULT),
                        $role,
                        $phone,
                        trim((string) ($_POST['address'] ?? '')),
                        $businessType,
                        $producerId,
                    ]);
                    $userId = (int) $db->lastInsertId();
                    if ($role === 'producer') {
                        $db->prepare('INSERT INTO producer_profiles (user_id, farm_size, irrigation, updated_at) VALUES (?, 0, ?, ?)')
                            ->execute([$userId, 'Non renseignée', date('Y-m-d H:i:s')]);
                    }
                    $db->commit();
                    session_regenerate_id(true);
                    $_SESSION['user_id'] = $userId;
                    flash($role === 'producer'
                        ? 'Votre compte producteur est créé. YAATAL vérifiera vos coordonnées avant tout partenariat.'
                        : 'Votre espace client est prêt.');
                    go(roleHome($role));
                }
            }
        }
    } elseif ($action === 'logout') {
        $_SESSION = [];
        session_regenerate_id(true);
        flash('Vous êtes déconnecté.');
        go('accueil');
    } elseif ($currentUser === null) {
        $errors[] = 'Connectez-vous pour continuer.';
    } else {
        switch ($action) {
            case 'place_order':
                requireRole($currentUser, 'client');
                $deliveryId = filter_var($_POST['delivery_id'] ?? null, FILTER_VALIDATE_INT);
                $deliveryStatement = $db->prepare("SELECT * FROM deliveries WHERE id = ? AND status = 'open' AND cutoff_at > ?");
                $deliveryStatement->execute([$deliveryId ?: 0, date('Y-m-d H:i:s')]);
                $delivery = $deliveryStatement->fetch();
                $address = trim((string) ($_POST['delivery_address'] ?? ''));
                $quantities = $_POST['quantities'] ?? [];
                $selectedProducts = [];
                $total = 0;
                if (!$delivery || $address === '' || !is_array($quantities)) {
                    $errors[] = 'Choisissez une date de ravitaillement ouverte et renseignez votre adresse de livraison.';
                    break;
                }
                $productQuery = $db->query(
                    'SELECT p.*, pr.village FROM products p JOIN producers pr ON pr.id = p.producer_id
                     WHERE p.is_available = 1 AND pr.active = 1'
                );
                $catalog = [];
                foreach ($productQuery->fetchAll() as $product) {
                    $catalog[(int) $product['id']] = $product;
                }
                foreach ($quantities as $productId => $quantityValue) {
                    $productId = filter_var($productId, FILTER_VALIDATE_INT);
                    $quantity = filter_var($quantityValue, FILTER_VALIDATE_INT);
                    if (!$productId || !$quantity || $quantity < 1) {
                        continue;
                    }
                    if (!isset($catalog[$productId]) || $quantity > (int) $catalog[$productId]['available_quantity']) {
                        $errors[] = 'Une quantité demandée dépasse le stock disponible. Vérifiez le catalogue.';
                        break;
                    }
                    $product = $catalog[$productId];
                    $reservationCheck = $db->prepare(
                        "SELECT COALESCE(SUM(oi.quantity), 0) FROM order_items oi JOIN orders o ON o.id = oi.order_id
                         WHERE oi.product_id = ? AND o.delivery_id = ? AND o.status != 'cancelled'"
                    );
                    $reservationCheck->execute([$productId, $delivery['id']]);
                    $alreadyReserved = (float) $reservationCheck->fetchColumn();
                    if ($alreadyReserved + $quantity > (float) $product['available_quantity']) {
                        $errors[] = 'Le stock restant est insuffisant pour une des références. Réduisez la quantité demandée.';
                        break;
                    }
                    $unitPrice = (int) $product['producer_price'] + (int) $product['commission'] + (int) $product['transport_fee'];
                    $selectedProducts[] = ['product' => $product, 'quantity' => $quantity, 'unit_price' => $unitPrice];
                    $total += $unitPrice * $quantity;
                }
                if (!$errors && !$selectedProducts) {
                    $errors[] = 'Ajoutez au moins un produit à votre commande.';
                }
                if (!$errors) {
                    $db->beginTransaction();
                    $reference = 'YT-' . date('ymd') . '-' . strtoupper(bin2hex(random_bytes(2)));
                    $statement = $db->prepare(
                        'INSERT INTO orders (reference, user_id, delivery_id, delivery_address, total, status, created_at)
                         VALUES (?, ?, ?, ?, ?, ?, ?)'
                    );
                    $statement->execute([$reference, $currentUser['id'], $delivery['id'], $address, $total, 'pending', date('Y-m-d H:i:s')]);
                    $orderId = (int) $db->lastInsertId();
                    $itemInsert = $db->prepare(
                        'INSERT INTO order_items (order_id, product_id, product_name, quantity, unit, producer_price, commission, transport_fee, unit_price)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)'
                    );
                    foreach ($selectedProducts as $selected) {
                        $product = $selected['product'];
                        $itemInsert->execute([
                            $orderId,
                            $product['id'],
                            $product['name'],
                            $selected['quantity'],
                            $product['unit'],
                            $product['producer_price'],
                            $product['commission'],
                            $product['transport_fee'],
                            $selected['unit_price'],
                        ]);
                    }
                    $db->prepare('UPDATE users SET address = ? WHERE id = ?')->execute([$address, $currentUser['id']]);
                    $db->commit();
                    flash('Précommande ' . $reference . ' enregistrée. YAATAL vous confirmera le ravitaillement.');
                    go('client');
                }
                break;

            case 'save_producer_profile':
                requireRole($currentUser, 'producer');
                $farmSize = filter_var($_POST['farm_size'] ?? null, FILTER_VALIDATE_FLOAT);
                $irrigation = trim((string) ($_POST['irrigation'] ?? ''));
                if ($farmSize === false || $farmSize <= 0 || $irrigation === '') {
                    $errors[] = 'Indiquez une surface positive et le mode d’accès à l’eau.';
                    break;
                }
                $db->prepare(
                    'INSERT INTO producer_profiles (user_id, farm_size, irrigation, updated_at) VALUES (?, ?, ?, ?)
                     ON CONFLICT(user_id) DO UPDATE SET farm_size = excluded.farm_size, irrigation = excluded.irrigation, updated_at = excluded.updated_at'
                )->execute([$currentUser['id'], $farmSize, $irrigation, date('Y-m-d H:i:s')]);
                $db->prepare('UPDATE producers SET climate = ? WHERE id = ?')
                    ->execute([in_array($_POST['climate'] ?? '', ['sec', 'normal', 'humide'], true) ? $_POST['climate'] : 'normal', $currentUser['producer_id']]);
                flash('Votre profil de parcelle a été enregistré. La prévision personnalisée peut maintenant utiliser vos rendements passés.');
                go('producteur');

            case 'save_yields':
                requireRole($currentUser, 'producer');
                $crops = $_POST['yield_crop'] ?? [];
                $years = $_POST['yield_year'] ?? [];
                $seasons = $_POST['yield_season'] ?? [];
                $amounts = $_POST['yield_amount'] ?? [];
                if (!is_array($crops) || !is_array($years) || !is_array($seasons) || !is_array($amounts)) {
                    $errors[] = 'Le tableau des rendements est invalide.';
                    break;
                }
                if (count($crops) > 100) {
                    $errors[] = 'Vous pouvez enregistrer jusqu’à 100 saisons passées à la fois.';
                    break;
                }
                $records = [];
                foreach ($crops as $index => $cropValue) {
                    $crop = trim((string) $cropValue);
                    $year = filter_var($years[$index] ?? null, FILTER_VALIDATE_INT);
                    $season = (string) ($seasons[$index] ?? '');
                    $amount = filter_var($amounts[$index] ?? null, FILTER_VALIDATE_FLOAT);
                    if ($crop === '' && ($years[$index] ?? '') === '' && $amount === false) {
                        continue;
                    }
                    if ($crop === '' || $year === false || $year < 2000 || $year > (int) date('Y') || !in_array($season, ['Saison fraîche', 'Saison chaude', 'Hivernage'], true) || $amount === false || $amount <= 0) {
                        $errors[] = 'Complétez chaque ligne de rendement ou retirez-la avant l’enregistrement.';
                        break;
                    }
                    $records[] = [$currentUser['id'], $crop, $year, $season, $amount];
                }
                if (!$errors && !$records) {
                    $errors[] = 'Ajoutez au moins une récolte passée pour calculer la prévision personnalisée.';
                    break;
                }
                if (!$errors) {
                    $db->beginTransaction();
                    $db->prepare('DELETE FROM yield_history WHERE user_id = ?')->execute([$currentUser['id']]);
                    $insert = $db->prepare('INSERT INTO yield_history (user_id, crop, year, season, yield_t_ha) VALUES (?, ?, ?, ?, ?)');
                    foreach ($records as $record) {
                        $insert->execute($record);
                    }
                    $db->commit();
                    flash('Vos ' . count($records) . ' rendements saisonniers sont enregistrés.');
                    go('producteur');
                }
                break;

            case 'submit_feedback':
                requireRole($currentUser, 'client');
                $orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT);
                $rating = filter_var($_POST['rating'] ?? null, FILTER_VALIDATE_INT);
                $message = trim((string) ($_POST['feedback_message'] ?? ''));
                $issue = trim((string) ($_POST['issue'] ?? ''));
                $statement = $db->prepare("SELECT id FROM orders WHERE id = ? AND user_id = ? AND status = 'delivered'");
                $statement->execute([$orderId ?: 0, $currentUser['id']]);
                if (!$statement->fetch() || $rating === false || $rating < 1 || $rating > 5 || $message === '' || ($rating <= 3 && $issue === '')) {
                    $errors[] = 'Votre avis doit concerner une commande livrée. Précisez le problème si la note est inférieure ou égale à 3.';
                    break;
                }
                $db->prepare('INSERT INTO feedback (order_id, user_id, rating, issue, message, created_at) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$orderId, $currentUser['id'], $rating, $issue, $message, date('Y-m-d H:i:s')]);
                flash('Merci pour votre retour. YAATAL l’utilisera pour améliorer le service.');
                go('client');

            case 'create_delivery':
                requireRole($currentUser, 'admin');
                $label = trim((string) ($_POST['label'] ?? ''));
                $region = trim((string) ($_POST['region'] ?? ''));
                $deliveryAt = strtotime((string) ($_POST['delivery_at'] ?? ''));
                $cutoffAt = strtotime((string) ($_POST['cutoff_at'] ?? ''));
                $quota = filter_var($_POST['target_kg'] ?? null, FILTER_VALIDATE_INT);
                if ($label === '' || $region === '' || !$deliveryAt || !$cutoffAt || $cutoffAt >= $deliveryAt || $deliveryAt <= time() || $quota === false || $quota < 1) {
                    $errors[] = 'Le ravitaillement doit avoir une date future, une clôture antérieure et un quota positif.';
                    break;
                }
                $db->prepare('INSERT INTO deliveries (label, region, delivery_at, cutoff_at, target_kg, status) VALUES (?, ?, ?, ?, ?, ?)')
                    ->execute([$label, $region, date('Y-m-d H:i:s', $deliveryAt), date('Y-m-d H:i:s', $cutoffAt), $quota, 'open']);
                flash('Le nouveau calendrier de ravitaillement est visible par les clients.');
                go('technique');

            case 'create_producer':
                requireRole($currentUser, 'admin');
                $name = trim((string) ($_POST['name'] ?? ''));
                $phone = trim((string) ($_POST['phone'] ?? ''));
                $village = trim((string) ($_POST['village'] ?? ''));
                $zone = (string) ($_POST['zone_group'] ?? '');
                if ($name === '' || $phone === '' || $village === '' || !in_array($zone, ['Niayes', 'Vallée du fleuve'], true)) {
                    $errors[] = 'Renseignez le nom, le téléphone, la localité et la zone du producteur.';
                    break;
                }
                $db->prepare('INSERT INTO producers (name, phone, village, zone_group, active) VALUES (?, ?, ?, ?, 1)')
                    ->execute([$name, $phone, $village, $zone]);
                flash('Le producteur partenaire a été ajouté à l’espace technique.');
                go('technique');

            case 'save_product':
                requireRole($currentUser, 'admin');
                $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
                $producerId = filter_var($_POST['producer_id'] ?? null, FILTER_VALIDATE_INT);
                $name = trim((string) ($_POST['name'] ?? ''));
                $category = trim((string) ($_POST['category'] ?? ''));
                $unit = (string) ($_POST['unit'] ?? '');
                $producerPrice = filter_var($_POST['producer_price'] ?? null, FILTER_VALIDATE_INT);
                $commission = filter_var($_POST['commission'] ?? null, FILTER_VALIDATE_INT);
                $transport = filter_var($_POST['transport_fee'] ?? null, FILTER_VALIDATE_INT);
                $stock = filter_var($_POST['available_quantity'] ?? null, FILTER_VALIDATE_FLOAT);
                $image = trim((string) ($_POST['image_url'] ?? ''));
                $producerCheck = $db->prepare('SELECT id FROM producers WHERE id = ? AND active = 1');
                $producerCheck->execute([$producerId ?: 0]);
                if ($name === '' || $category === '' || !in_array($unit, ['kg', 'pièce', 'botte', 'caisse'], true) || $producerPrice === false || $producerPrice < 0 || $commission === false || $commission < 0 || $transport === false || $transport < 0 || $stock === false || $stock < 0 || !$producerCheck->fetch()) {
                    $errors[] = 'Vérifiez le producteur partenaire, l’unité, le stock et les trois composantes du prix.';
                    break;
                }
                if ($productId) {
                    $db->prepare(
                        'UPDATE products SET producer_id = ?, name = ?, category = ?, unit = ?, producer_price = ?, commission = ?, transport_fee = ?, available_quantity = ?, image_url = ?, is_available = 1 WHERE id = ?'
                    )->execute([$producerId, $name, $category, $unit, $producerPrice, $commission, $transport, $stock, $image, $productId]);
                } else {
                    $db->prepare(
                        'INSERT INTO products (producer_id, name, category, unit, producer_price, commission, transport_fee, available_quantity, image_url, is_available) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 1)'
                    )->execute([$producerId, $name, $category, $unit, $producerPrice, $commission, $transport, $stock, $image]);
                }
                flash('Le catalogue YAATAL a été mis à jour. Le client voit le prix complet, transport inclus.');
                go('technique');

            case 'create_supplier_order':
                requireRole($currentUser, 'admin');
                $producerId = filter_var($_POST['producer_id'] ?? null, FILTER_VALIDATE_INT);
                $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
                $deliveryId = filter_var($_POST['delivery_id'] ?? null, FILTER_VALIDATE_INT);
                $quantity = filter_var($_POST['quantity'] ?? null, FILTER_VALIDATE_FLOAT);
                $statement = $db->prepare('SELECT producer_price FROM products WHERE id = ? AND producer_id = ? AND is_available = 1');
                $statement->execute([$productId ?: 0, $producerId ?: 0]);
                $productPrice = $statement->fetchColumn();
                $deliveryCheck = $db->prepare("SELECT id FROM deliveries WHERE id = ? AND status = 'open' AND delivery_at > ?");
                $deliveryCheck->execute([$deliveryId ?: 0, date('Y-m-d H:i:s')]);
                if ($productPrice === false || !$deliveryCheck->fetch() || $quantity === false || $quantity <= 0) {
                    $errors[] = 'Sélectionnez un produit de ce producteur, une date et une quantité positive.';
                    break;
                }
                $db->prepare(
                    "INSERT INTO supplier_orders (producer_id, product_id, delivery_id, quantity, producer_unit_price, status, created_at)
                     VALUES (?, ?, ?, ?, ?, 'à planifier', ?)"
                )->execute([$producerId, $productId, $deliveryId, $quantity, $productPrice, date('Y-m-d H:i:s')]);
                flash('Le bon d’achat YAATAL est créé séparément des commandes clients. Planifiez le contrôle qualité avant validation.');
                go('technique');

            case 'schedule_quality':
                requireRole($currentUser, 'admin');
                $supplierOrderId = filter_var($_POST['supplier_order_id'] ?? null, FILTER_VALIDATE_INT);
                $scheduledAt = strtotime((string) ($_POST['scheduled_at'] ?? ''));
                $notes = trim((string) ($_POST['notes'] ?? ''));
                $statement = $db->prepare(
                    'SELECT so.id, d.delivery_at FROM supplier_orders so JOIN deliveries d ON d.id = so.delivery_id WHERE so.id = ?'
                );
                $statement->execute([$supplierOrderId ?: 0]);
                $supplierOrder = $statement->fetch();
                if (!$supplierOrder || !$scheduledAt || $scheduledAt <= time() || $scheduledAt >= strtotime($supplierOrder['delivery_at']) || $notes === '') {
                    $errors[] = 'Le rendez-vous doit avoir lieu avant le ravitaillement. Indiquez une date future et les points à contrôler.';
                    break;
                }
                $db->beginTransaction();
                $db->prepare(
                    "INSERT INTO quality_visits (supplier_order_id, scheduled_at, notes, status, created_at) VALUES (?, ?, ?, 'planifiée', ?)"
                )->execute([$supplierOrderId, date('Y-m-d H:i:s', $scheduledAt), $notes, date('Y-m-d H:i:s')]);
                $db->prepare("UPDATE supplier_orders SET status = 'visite planifiée' WHERE id = ?")->execute([$supplierOrderId]);
                $db->commit();
                flash('Rendez-vous de contrôle programmé. Le bon restera non validé jusqu’au résultat du contrôle.');
                go('technique');

            case 'quality_result':
                requireRole($currentUser, 'admin');
                $visitId = filter_var($_POST['visit_id'] ?? null, FILTER_VALIDATE_INT);
                $outcome = (string) ($_POST['outcome'] ?? '');
                if (!in_array($outcome, ['favorable', 'défavorable'], true)) {
                    $errors[] = 'Choisissez un résultat de contrôle valide.';
                    break;
                }
                $statement = $db->prepare(
                    "SELECT qv.supplier_order_id FROM quality_visits qv
                     JOIN supplier_orders so ON so.id = qv.supplier_order_id
                     WHERE qv.id = ? AND qv.status = 'planifiée' AND so.status = 'visite planifiée'"
                );
                $statement->execute([$visitId ?: 0]);
                $supplierOrderId = $statement->fetchColumn();
                if (!$supplierOrderId) {
                    $errors[] = 'Ce rendez-vous de contrôle est introuvable.';
                    break;
                }
                $db->beginTransaction();
                $db->prepare('UPDATE quality_visits SET status = ? WHERE id = ?')->execute([$outcome, $visitId]);
                $db->prepare('UPDATE supplier_orders SET status = ? WHERE id = ?')
                    ->execute([$outcome === 'favorable' ? 'validée' : 'bloquée', $supplierOrderId]);
                $db->commit();
                flash($outcome === 'favorable'
                    ? 'Contrôle favorable : le bon fournisseur YAATAL est validé.'
                    : 'Contrôle défavorable : le bon fournisseur est bloqué en attendant une décision.');
                go('technique');

            case 'update_client_order':
                requireRole($currentUser, 'admin');
                $orderId = filter_var($_POST['order_id'] ?? null, FILTER_VALIDATE_INT);
                $status = (string) ($_POST['status'] ?? '');
                if (!in_array($status, ['confirmed', 'preparing', 'delivered', 'cancelled'], true)) {
                    $errors[] = 'Statut de commande non reconnu.';
                    break;
                }
                $db->prepare('UPDATE orders SET status = ? WHERE id = ?')->execute([$status, $orderId ?: 0]);
                flash('Le suivi de livraison YAATAL a été mis à jour.');
                go('technique');

            case 'toggle_product':
                requireRole($currentUser, 'admin');
                $productId = filter_var($_POST['product_id'] ?? null, FILTER_VALIDATE_INT);
                $db->prepare('UPDATE products SET is_available = CASE is_available WHEN 1 THEN 0 ELSE 1 END WHERE id = ?')
                    ->execute([$productId ?: 0]);
                flash('La disponibilité du produit a été actualisée.');
                go('technique');

            case 'toggle_producer':
                requireRole($currentUser, 'admin');
                $producerId = filter_var($_POST['producer_id'] ?? null, FILTER_VALIDATE_INT);
                $db->prepare('UPDATE producers SET active = CASE active WHEN 1 THEN 0 ELSE 1 END WHERE id = ?')
                    ->execute([$producerId ?: 0]);
                flash('Le statut du producteur a été actualisé. Les produits associés suivent ce statut dans le catalogue client.');
                go('technique');

            default:
                $errors[] = 'Action non reconnue.';
        }
    }
}

$page = (string) ($_GET['page'] ?? ($currentUser ? roleHome($currentUser['role']) : 'accueil'));
$allowedPages = ['client' => 'client', 'producteur' => 'producer', 'technique' => 'admin'];
if ($currentUser !== null) {
    if (!isset($allowedPages[$page]) || $allowedPages[$page] !== $currentUser['role']) {
        go(roleHome($currentUser['role']));
    }
} elseif (!in_array($page, ['accueil', 'inscription'], true)) {
    $errors[] = 'Connectez-vous pour accéder à cet espace.';
    $page = 'accueil';
}

$flashMessage = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
$csrf = csrfToken();
$seasons = ['Saison fraîche', 'Saison chaude', 'Hivernage'];
$season = in_array($_GET['saison'] ?? '', $seasons, true) ? (string) $_GET['saison'] : seasonFor(new DateTimeImmutable());
$climate = in_array($_GET['climat'] ?? '', ['sec', 'normal', 'humide'], true) ? (string) $_GET['climat'] : 'normal';
$climateNames = ['sec' => 'Pluviométrie faible', 'normal' => 'Pluviométrie modérée', 'humide' => 'Pluviométrie forte'];

$catalog = [];
$deliveries = [];
$clientOrders = [];
$clientOrderItems = [];
$producer = null;
$producerProfile = null;
$yieldRows = [];
$globalPredictions = [];
$personalPredictions = [];
$producers = [];
$products = [];
$adminDeliveries = [];
$supplierOrders = [];
$qualityVisits = [];
$adminOrders = [];
$adminOrderItems = [];
$groupedDemand = [];
$feedbackRows = [];

if ($currentUser !== null && $currentUser['role'] === 'client') {
    $catalog = $db->query(
        'SELECT p.*, pr.village FROM products p JOIN producers pr ON pr.id = p.producer_id
         WHERE p.is_available = 1 AND p.available_quantity > 0 AND pr.active = 1 ORDER BY p.category, p.name'
    )->fetchAll();
    $deliveries = $db->prepare("SELECT * FROM deliveries WHERE status = 'open' AND cutoff_at > ? ORDER BY delivery_at");
    $deliveries->execute([date('Y-m-d H:i:s')]);
    $deliveries = $deliveries->fetchAll();
    $clientOrders = $db->prepare('SELECT o.*, d.label, d.region, d.delivery_at FROM orders o JOIN deliveries d ON d.id = o.delivery_id WHERE o.user_id = ? ORDER BY o.created_at DESC');
    $clientOrders->execute([$currentUser['id']]);
    $clientOrders = $clientOrders->fetchAll();
    foreach ($clientOrders as $clientOrder) {
        $statement = $db->prepare('SELECT * FROM order_items WHERE order_id = ?');
        $statement->execute([$clientOrder['id']]);
        $clientOrderItems[$clientOrder['id']] = $statement->fetchAll();
    }
}

if ($currentUser !== null && $currentUser['role'] === 'producer') {
    $statement = $db->prepare('SELECT * FROM producers WHERE id = ?');
    $statement->execute([$currentUser['producer_id']]);
    $producer = $statement->fetch();
    $statement = $db->prepare('SELECT * FROM producer_profiles WHERE user_id = ?');
    $statement->execute([$currentUser['id']]);
    $producerProfile = $statement->fetch();
    if (!isset($_GET['climat']) && isset($producer['climate'])) {
        $climate = $producer['climate'];
    }
    $statement = $db->prepare('SELECT * FROM yield_history WHERE user_id = ? ORDER BY year DESC, id DESC');
    $statement->execute([$currentUser['id']]);
    $yieldRows = $statement->fetchAll();
    $statement = $db->prepare('SELECT * FROM predictions WHERE season = ? AND zone_group = ? AND climate = ? ORDER BY crop');
    $statement->execute([$season, $producer['zone_group'] ?? 'Niayes', $climate]);
    $globalPredictions = $statement->fetchAll();
    if ($producerProfile && $yieldRows) {
        foreach ($globalPredictions as $prediction) {
            $statement = $db->prepare('SELECT AVG(yield_t_ha), COUNT(*) FROM yield_history WHERE user_id = ? AND crop = ?');
            $statement->execute([$currentUser['id'], $prediction['crop']]);
            [$historicalAverage, $count] = $statement->fetch(PDO::FETCH_NUM);
            $statement = $db->prepare('SELECT AVG(expected_yield) FROM predictions WHERE season = ? AND zone_group = ? AND crop = ?');
            $statement->execute([$season, $producer['zone_group'], $prediction['crop']]);
            $zoneAverage = (float) $statement->fetchColumn();
            if ((int) $count > 0 && $zoneAverage > 0) {
                $personalPredictions[] = [
                    'crop' => $prediction['crop'],
                    'history' => (float) $historicalAverage,
                    'projection' => round((float) $historicalAverage * (float) $prediction['expected_yield'] / $zoneAverage, 1),
                    'count' => (int) $count,
                ];
            }
        }
    }
}

if ($currentUser !== null && $currentUser['role'] === 'admin') {
    $producers = $db->query(
        'SELECT pr.*, GROUP_CONCAT(p.name, " · ") AS product_names FROM producers pr LEFT JOIN products p ON p.producer_id = pr.id
         GROUP BY pr.id ORDER BY pr.active DESC, pr.name'
    )->fetchAll();
    $products = $db->query('SELECT p.*, pr.name AS producer_name, pr.active AS producer_active FROM products p JOIN producers pr ON pr.id = p.producer_id ORDER BY p.name')->fetchAll();
    $adminDeliveries = $db->query('SELECT * FROM deliveries ORDER BY delivery_at DESC LIMIT 20')->fetchAll();
    $supplierOrders = $db->query(
        'SELECT so.*, p.name AS product_name, p.unit, pr.name AS producer_name, pr.phone, pr.village, d.label AS delivery_label, d.delivery_at
         FROM supplier_orders so JOIN products p ON p.id = so.product_id JOIN producers pr ON pr.id = so.producer_id
         JOIN deliveries d ON d.id = so.delivery_id ORDER BY so.created_at DESC'
    )->fetchAll();
    $qualityVisits = $db->query(
        'SELECT qv.*, so.product_id, p.name AS product_name, pr.name AS producer_name, pr.village, so.quantity
         FROM quality_visits qv JOIN supplier_orders so ON so.id = qv.supplier_order_id
         JOIN products p ON p.id = so.product_id JOIN producers pr ON pr.id = so.producer_id ORDER BY qv.scheduled_at DESC'
    )->fetchAll();
    $adminOrders = $db->query(
        'SELECT o.*, u.name AS client_name, u.phone, u.business_type, d.label AS delivery_label, d.delivery_at
         FROM orders o JOIN users u ON u.id = o.user_id JOIN deliveries d ON d.id = o.delivery_id ORDER BY o.created_at DESC LIMIT 100'
    )->fetchAll();
    $groupedDemand = $db->query(
        "SELECT d.id AS delivery_id, d.label, d.delivery_at, p.name AS product_name, p.unit, pr.name AS producer_name,
                SUM(oi.quantity) AS quantity, COUNT(DISTINCT o.id) AS client_orders
         FROM order_items oi JOIN orders o ON o.id = oi.order_id JOIN deliveries d ON d.id = o.delivery_id
         JOIN products p ON p.id = oi.product_id JOIN producers pr ON pr.id = p.producer_id
         WHERE o.status != 'cancelled'
         GROUP BY d.id, p.id ORDER BY d.delivery_at, pr.name, p.name"
    )->fetchAll();
    $feedbackRows = $db->query(
        'SELECT f.*, o.reference, u.name AS client_name FROM feedback f JOIN orders o ON o.id = f.order_id JOIN users u ON u.id = f.user_id ORDER BY f.created_at DESC LIMIT 30'
    )->fetchAll();
}

function money(mixed $amount): string
{
    return number_format((float) $amount, 0, ',', ' ') . ' FCFA';
}

function dateFr(string $date, bool $time = false): string
{
    $format = $time ? 'd/m/Y à H\hi' : 'd/m/Y';
    return (new DateTimeImmutable($date))->format($format);
}

$statusNames = [
    'pending' => 'Précommande reçue',
    'confirmed' => 'Ravitaillement confirmé',
    'preparing' => 'Préparation YAATAL',
    'delivered' => 'Livrée par YAATAL',
    'cancelled' => 'Annulée',
];
$seasonBadge = seasonFor(new DateTimeImmutable());
?>
<!doctype html>
<html lang="fr">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="theme-color" content="#183c22">
  <meta name="description" content="YAATAL organise le ravitaillement et la livraison directe du producteur au marché.">
  <title>YAATAL — Du producteur au marché, sans obstacle.</title>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=Playfair+Display:wght@600;700;800&display=swap" rel="stylesheet">
  <link rel="stylesheet" href="styles.css">
</head>
<body>
<?php if ($currentUser === null): ?>
  <header class="site-header public-header">
    <a class="brand" href="index.php"><span class="brand-mark" aria-hidden="true"><span></span><span></span></span><span class="brand-name">yaatal<small>DU PRODUCTEUR AU MARCHÉ</small></span></a>
    <a class="text-link" href="index.php?page=inscription">Créer un espace <span>→</span></a>
  </header>
  <main class="public-main">
    <section class="public-hero">
      <div class="public-photo">
        <div class="public-photo-caption"><span class="caption-icon">✳</span><span><strong>La récolte, à sa juste place.</strong><small>Producteurs, commerçants et équipe YAATAL.</small></span></div>
      </div>
      <div class="public-copy">
        <div class="eyebrow"><span class="eyebrow-line"></span> LA TERRE NOUS RASSEMBLE</div>
        <h1>Du producteur<br>au marché,<br><em>sans obstacle.</em></h1>
        <p>YAATAL organise les commandes groupées, les contrôles au champ et la livraison par ses propres équipes.</p>
        <div class="public-points"><span>✦ Ravitaillements programmés</span><span>✦ Prix affiché, transport YAATAL compris</span><span>✦ Qualité contrôlée avant validation</span></div>
      </div>
    </section>
    <?php if ($errors || $flashMessage): ?><div class="notice-wrap">
      <?php foreach ($errors as $error): ?><div class="notice error"><?= e($error) ?></div><?php endforeach; ?>
      <?php if ($flashMessage): ?><div class="notice <?= e($flashMessage['kind']) ?>"><?= e($flashMessage['message']) ?></div><?php endif; ?>
    </div><?php endif; ?>
    <section class="auth-grid">
      <article class="auth-card">
        <div class="eyebrow"><span class="eyebrow-line"></span> ESPACE MEMBRE</div>
        <h2>Ravi de vous revoir.</h2>
        <p>Connectez-vous à votre espace YAATAL.</p>
        <form class="form-grid" method="post">
          <input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="login">
          <label>E-mail<input type="email" name="email" required autocomplete="username" placeholder="vous@exemple.sn"></label>
          <label>Mot de passe<input type="password" name="password" required autocomplete="current-password" placeholder="Votre mot de passe"></label>
          <button class="button button-green" type="submit">Se connecter <span>→</span></button>
        </form>
        <details class="demo-access"><summary>Accès de démonstration locale</summary><p>Mot de passe commun : <strong>Yaatal2026!</strong></p><p><code>client@yaatal.local</code> · <code>producteur@yaatal.local</code> · <code>admin@yaatal.local</code></p><small>À remplacer avant toute mise en ligne publique.</small></details>
      </article>
      <article class="auth-card auth-card-alt">
        <div class="eyebrow"><span class="eyebrow-line"></span> NOUS REJOINDRE</div>
        <h2>Créer votre espace.</h2>
        <p>Ouvrez un espace client ou producteur. Les nouveaux producteurs sont vérifiés par YAATAL avant d’entrer au catalogue.</p>
        <a class="button button-outline" href="index.php?page=inscription">Choisir mon profil <span>↗</span></a>
      </article>
    </section>
  </main>
<?php elseif ($page === 'inscription'): ?>
  <header class="site-header public-header"><a class="brand" href="index.php"><span class="brand-mark" aria-hidden="true"><span></span><span></span></span><span class="brand-name">yaatal<small>DU PRODUCTEUR AU MARCHÉ</small></span></a><a class="text-link" href="index.php">Retour connexion <span>→</span></a></header>
  <main class="register-main">
    <div class="register-heading"><div class="eyebrow"><span class="eyebrow-line"></span> BIENVENUE DANS LA FILIÈRE</div><h1>Choisissez votre place<br><em>dans le parcours.</em></h1></div>
    <?php if ($errors): ?><div class="notice-wrap"><?php foreach ($errors as $error): ?><div class="notice error"><?= e($error) ?></div><?php endforeach; ?></div><?php endif; ?>
    <section class="register-grid">
      <article class="auth-card"><span class="auth-icon">🧺</span><h2>Je suis acheteur.</h2><p>Commerçant, restaurant, marché ou magasin. Choisissez un ravitaillement et commandez avant la date limite.</p>
        <form class="form-grid" method="post"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="register"><input type="hidden" name="role" value="client">
          <label>Établissement<input name="name" required placeholder="Nom de votre établissement"></label><label>Type d’établissement<select name="business_type" required><option value="">Choisir</option><option>Petit commerce</option><option>Commerce moyen</option><option>Grand commerce</option><option>Restaurant</option><option>Supermarché</option><option>Marché</option><option>Autre</option></select></label><label>E-mail<input type="email" name="email" required placeholder="vous@exemple.sn"></label><label>Téléphone<input type="tel" name="phone" required placeholder="+221 77 000 00 00"></label><label>Mot de passe<input type="password" name="password" minlength="10" required autocomplete="new-password"></label><button class="button button-green" type="submit">Créer mon espace client <span>→</span></button>
        </form>
      </article>
      <article class="auth-card"><span class="auth-icon">🌱</span><h2>Je suis producteur.</h2><p>Consultez les repères de culture globaux et, si vous le souhaitez, personnalisez-les avec votre profil et vos anciennes récoltes.</p>
        <form class="form-grid" method="post"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="register"><input type="hidden" name="role" value="producer">
          <label>Nom et prénom<input name="name" required placeholder="Votre nom"></label><label>E-mail<input type="email" name="email" required placeholder="vous@exemple.sn"></label><label>Téléphone<input type="tel" name="phone" required placeholder="+221 77 000 00 00"></label><label>Village / localité<input name="village" required placeholder="Ex. : Pout, Thiès"></label><label>Zone agricole<select name="zone_group" required><option value="">Choisir une zone</option><option>Niayes</option><option>Vallée du fleuve</option></select></label><label>Mot de passe<input type="password" name="password" minlength="10" required autocomplete="new-password"></label><button class="button button-green" type="submit">Créer mon espace producteur <span>→</span></button>
        </form>
      </article>
    </section>
  </main>
<?php else: ?>
  <?php
    $navigation = match ($currentUser['role']) {
        'client' => [['client', 'Catalogue'], ['client#mes-commandes', 'Mes commandes']],
        'producer' => [['producteur#modele-global', 'Prévisions de culture'], ['producteur#profil', 'Mon exploitation']],
        default => [['technique#pilotage', 'Pilotage'], ['technique#producteurs', 'Producteurs'], ['technique#approvisionnements', 'Achats YAATAL'], ['technique#qualite', 'Contrôle qualité']],
    };
  ?>
  <div class="announcement"><span class="announcement-dot"></span> Récoltes locales · <strong>Du producteur au marché</strong><span>Livraison organisée par YAATAL</span></div>
  <header class="site-header">
    <a class="brand" href="index.php?page=<?= e(roleHome($currentUser['role'])) ?>"><span class="brand-mark" aria-hidden="true"><span></span><span></span></span><span class="brand-name">yaatal<small>DU PRODUCTEUR AU MARCHÉ</small></span></a>
    <button class="menu-toggle" aria-label="Ouvrir le menu" aria-expanded="false">☰</button>
    <nav class="main-nav" aria-label="Navigation principale"><?php foreach ($navigation as [$target, $label]): ?><a href="index.php?page=<?= e(explode('#', $target)[0]) ?><?= str_contains($target, '#') ? '#' . e(explode('#', $target)[1]) : '' ?>"><?= e($label) ?></a><?php endforeach; ?></nav>
    <div class="header-actions"><span class="account-name"><?= e($currentUser['name']) ?></span><form method="post" class="logout-form"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="logout"><button class="button button-outline button-small" type="submit">Déconnexion</button></form></div>
  </header>
  <?php if ($errors || $flashMessage): ?><div class="notice-wrap">
    <?php foreach ($errors as $error): ?><div class="notice error"><?= e($error) ?></div><?php endforeach; ?>
    <?php if ($flashMessage): ?><div class="notice <?= e($flashMessage['kind']) ?>"><?= e($flashMessage['message']) ?></div><?php endif; ?>
  </div><?php endif; ?>

  <?php if ($currentUser['role'] === 'client'): ?>
    <main class="dashboard">
      <section class="portal-hero client-hero"><div class="portal-hero-content"><div class="eyebrow light-eyebrow"><span class="eyebrow-line"></span> ESPACE CLIENT · PROFESSIONNELS</div><h1>Vos récoltes.<br><em>Votre calendrier.</em></h1><p>Choisissez la date de ravitaillement qui vous convient. YAATAL regroupe vos commandes et livre avec ses propres équipes.</p><span class="hero-tag">🚚 &nbsp; Livraison par les équipes YAATAL</span></div></section>
      <div class="section-title" id="catalogue"><div><div class="eyebrow"><span class="eyebrow-line"></span> CATALOGUE PILOTE</div><h2>Produits disponibles <em>à la commande.</em></h2><p>Références proposées par les producteurs partenaires de YAATAL.</p></div><div class="pilot-note">Données de démonstration<br>à remplacer par le catalogue validé</div></div>
      <?php if (!$catalog): ?><div class="empty-state"><span>🥬</span><h3>Le prochain catalogue se prépare.</h3><p>YAATAL publiera ici les récoltes disponibles auprès de ses producteurs partenaires.</p></div><?php else: ?>
        <form method="post" class="order-form">
          <input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="place_order">
          <div class="order-toolbar"><label class="delivery-select">Choisir votre date de ravitaillement<select name="delivery_id" required><option value="">Sélectionnez une date</option><?php foreach ($deliveries as $delivery): ?><option value="<?= e($delivery['id']) ?>"><?= e($delivery['label']) ?> · <?= e(dateFr($delivery['delivery_at'])) ?> · clôture <?= e(dateFr($delivery['cutoff_at'], true)) ?></option><?php endforeach; ?></select></label><span class="delivery-trust">Le prix affiché comprend la commission et le transport YAATAL.</span></div>
          <?php if (!$deliveries): ?><div class="notice">Aucune date n’est ouverte pour le moment. YAATAL annoncera le prochain ravitaillement.</div><?php endif; ?>
          <div class="product-grid"><?php foreach ($catalog as $item): $salePrice = (int) $item['producer_price'] + (int) $item['commission'] + (int) $item['transport_fee']; ?>
            <article class="product-card" data-unit-price="<?= e($salePrice) ?>">
              <div class="product-photo"><?php if ($item['image_url']): ?><img src="<?= e($item['image_url']) ?>" alt="<?= e($item['name']) ?>" loading="lazy"><?php endif; ?><span class="season-tag">PARTENAIRE YAATAL</span></div>
              <div class="product-info"><div class="product-location">● &nbsp; <?= e($item['village']) ?></div><h3><?= e($item['name']) ?></h3>
                <div class="price-main"><?= e(money($salePrice)) ?><small> / <?= e($item['unit']) ?></small></div>
                <details class="price-detail"><summary>Voir le prix complet</summary><span>Producteur : <?= e(money($item['producer_price'])) ?></span><span>Commission YAATAL : <?= e(money($item['commission'])) ?></span><span>Transport YAATAL : <?= e(money($item['transport_fee'])) ?></span></details>
                <div class="stock-line">Disponibles : <?= e(number_format((float) $item['available_quantity'], 0, ',', ' ')) ?> <?= e($item['unit']) ?></div>
                <label class="quantity-label">Quantité (<?= e($item['unit']) ?>)<input type="number" min="0" max="<?= e((int) $item['available_quantity']) ?>" step="1" name="quantities[<?= e($item['id']) ?>]" value="0"></label>
              </div>
            </article>
          <?php endforeach; ?></div>
          <div class="order-summary"><span>Estimation de la sélection · transport YAATAL inclus</span><strong id="liveOrderTotal">0 FCFA</strong></div>
          <div class="order-submit"><label>Adresse de livraison<input name="delivery_address" required value="<?= e($currentUser['address']) ?>" placeholder="Adresse de votre commerce"></label><button class="button button-green" type="submit" <?= !$deliveries ? 'disabled' : '' ?>>Valider ma précommande <span>→</span></button></div>
        </form>
      <?php endif; ?>
      <section class="history-section" id="mes-commandes"><div class="section-title"><div><div class="eyebrow"><span class="eyebrow-line"></span> VOTRE SUIVI</div><h2>Mes commandes <em>YAATAL.</em></h2><p>Historique de vos précommandes, ravitaillements et livraisons.</p></div></div>
        <?php if (!$clientOrders): ?><div class="empty-state compact-empty"><span>📦</span><h3>Pas encore de commande.</h3><p>Votre suivi apparaîtra ici après votre première précommande.</p></div><?php else: ?>
          <div class="orders-list"><?php foreach ($clientOrders as $order): ?>
            <article class="order-card"><div class="order-card-top"><div><span class="order-reference"><?= e($order['reference']) ?></span><h3><?= e($order['label']) ?> · <?= e(dateFr($order['delivery_at'])) ?></h3></div><span class="status-badge status-<?= e($order['status']) ?>"><?= e($statusNames[$order['status']] ?? $order['status']) ?></span></div><p class="order-address">Livraison par YAATAL · <?= e($order['delivery_address']) ?></p><div class="order-item-list"><?php foreach ($clientOrderItems[$order['id']] as $line): ?><span><?= e($line['quantity']) ?> <?= e($line['unit']) ?> · <?= e($line['product_name']) ?> <strong><?= e(money((float) $line['quantity'] * (int) $line['unit_price'])) ?></strong></span><?php endforeach; ?></div><div class="order-total"><span>Total (transport YAATAL inclus)</span><strong><?= e(money($order['total'])) ?></strong></div>
              <?php if ($order['status'] === 'delivered'): $feedbackCheck = $db->prepare('SELECT id FROM feedback WHERE order_id = ?'); $feedbackCheck->execute([$order['id']]); if (!$feedbackCheck->fetch()): ?>
                <form method="post" class="feedback-form"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="submit_feedback"><input type="hidden" name="order_id" value="<?= e($order['id']) ?>"><h4>Votre réception s’est-elle bien passée ?</h4><div class="feedback-fields"><label>Note<select name="rating" required><option value="5">5 — Très bien</option><option value="4">4 — Bien</option><option value="3">3 — Moyen</option><option value="2">2 — Insatisfaisant</option><option value="1">1 — Problème important</option></select></label><label>Problème rencontré (obligatoire si la note ≤ 3)<select name="issue"><option value="">Aucun / autre</option><option>Qualité des produits</option><option>Produit ou quantité manquante</option><option>Retard de livraison YAATAL</option><option>État à la réception</option><option>Autre problème</option></select></label><label class="feedback-message">Votre commentaire<textarea name="feedback_message" required placeholder="Racontez-nous votre expérience."></textarea></label><button class="button button-outline button-small" type="submit">Envoyer mon avis</button></div></form>
              <?php endif; endif; ?></article>
          <?php endforeach; ?></div>
        <?php endif; ?>
      </section>
    </main>

  <?php elseif ($currentUser['role'] === 'producer'): ?>
    <main class="dashboard">
      <section class="portal-hero producer-hero"><div class="portal-hero-content"><div class="eyebrow light-eyebrow"><span class="eyebrow-line"></span> ESPACE PRODUCTEUR · <?= e($producer['village'] ?? '') ?></div><h1>Votre terre,<br><em>vos saisons.</em></h1><p>Commencez par les repères globaux de votre zone. Le modèle personnel est facultatif et s’affine grâce à vos anciennes récoltes.</p><span class="hero-tag">Les producteurs ne publient pas le catalogue client.</span></div></section>
      <section class="forecast-section producer-forecast" id="modele-global">
        <div class="forecast-intro"><div class="eyebrow"><span class="eyebrow-line"></span> MODÈLE GLOBAL YAATAL</div><h2>Des repères pour<br><em>la saison qui vient.</em></h2><p>Scénarios issus d’un jeu de données fictif inspiré d’entretiens producteurs. Ils croisent saison, grande zone agricole et scénario climatique ; ils ne remplacent pas un conseil agronomique.</p><div class="model-source">Zone : <strong><?= e($producer['zone_group'] ?? 'Niayes') ?></strong> · Saison actuelle : <strong><?= e($seasonBadge) ?></strong></div></div>
        <div class="forecast-card">
          <form method="get" class="forecast-filters"><input type="hidden" name="page" value="producteur"><label>Saison<select name="saison"><?php foreach ($seasons as $option): ?><option <?= $season === $option ? 'selected' : '' ?>><?= e($option) ?></option><?php endforeach; ?></select></label><label>Scénario de pluies<select name="climat"><?php foreach ($climateNames as $code => $label): ?><option value="<?= e($code) ?>" <?= $climate === $code ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label><button class="button button-green button-small" type="submit">Actualiser</button></form>
          <div class="forecast-card-head"><span class="forecast-icon">☀</span><span><strong>Scénario <?= e($climateNames[$climate]) ?></strong><small><?= e($producer['zone_group'] ?? 'Niayes') ?> · <?= e($season) ?></small></span><span class="forecast-pill">MODÈLE GLOBAL</span></div>
          <div class="forecast-rows"><?php foreach ($globalPredictions as $prediction): ?><div class="forecast-row"><span class="crop-icon"><?= e(match ($prediction['crop']) { 'Tomate' => '🍅', 'Carotte' => '🥕', 'Oignon' => '🧅', default => '🥬' }) ?></span><span class="forecast-description"><strong><?= e($prediction['crop']) ?></strong><small>Semis : <?= e($prediction['sowing_window']) ?> · <?= e($prediction['notes']) ?></small></span><span class="forecast-fit"><?= e($prediction['suitability']) ?><small><?= e(number_format((float) $prediction['expected_yield'], 1, ',', ' ')) ?> t/ha*</small></span></div><?php endforeach; ?></div>
          <div class="forecast-disclaimer">* Rendements de scénario fictifs, pas une promesse de récolte. Vérifier l’eau, le sol et les conseils locaux.</div>
        </div>
      </section>
      <section class="personal-section" id="profil"><div class="section-title"><div><div class="eyebrow"><span class="eyebrow-line"></span> OPTIONNEL · À VOTRE RYTHME</div><h2>Affiner avec <em>vos propres récoltes.</em></h2><p>Le profil est facultatif. Enregistrez plusieurs saisons passées pour obtenir une projection indicative basée sur votre moyenne et le scénario global.</p></div><span class="optional-badge">Votre choix</span></div>
        <div class="producer-tools-grid">
          <article class="panel"><div class="panel-heading"><span class="panel-icon">🌿</span><div><h3>Mon exploitation</h3><p>Surface et accès à l’eau</p></div></div>
            <form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="save_producer_profile"><label>Surface cultivée (hectares)<input type="number" name="farm_size" min="0.01" step="0.01" required value="<?= e($producerProfile['farm_size'] ?? '') ?>" placeholder="Ex. : 1,5"></label><label>Mode d’accès à l’eau<input name="irrigation" required value="<?= e($producerProfile['irrigation'] ?? '') ?>" placeholder="Ex. : goutte-à-goutte, pluie"></label><label>Scénario de pluies à utiliser<select name="climate"><?php foreach ($climateNames as $code => $label): ?><option value="<?= e($code) ?>" <?= ($producer['climate'] ?? 'normal') === $code ? 'selected' : '' ?>><?= e($label) ?></option><?php endforeach; ?></select></label><button class="button button-green" type="submit">Enregistrer mon profil <span>→</span></button></form>
          </article>
          <article class="panel"><div class="panel-heading"><span class="panel-icon">📈</span><div><h3>Mes rendements passés</h3><p>Ajoutez autant de saisons que vous connaissez</p></div></div>
            <form method="post" class="form-grid" id="yieldForm"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="save_yields"><div class="yield-table-head"><span>Culture</span><span>Année</span><span>Saison</span><span>t/ha</span><span></span></div><div id="yieldRows">
              <?php $rowsToDisplay = $yieldRows ?: [['crop' => '', 'year' => '', 'season' => '', 'yield_t_ha' => '']]; foreach ($rowsToDisplay as $yield): ?>
                <div class="yield-row"><input name="yield_crop[]" value="<?= e($yield['crop']) ?>" aria-label="Culture" placeholder="Ex. : Tomate"><input name="yield_year[]" type="number" min="2000" max="<?= e(date('Y')) ?>" value="<?= e($yield['year']) ?>" aria-label="Année" placeholder="<?= e(date('Y') - 1) ?>"><select name="yield_season[]" aria-label="Saison"><option value="">Saison</option><?php foreach ($seasons as $option): ?><option <?= ($yield['season'] ?? '') === $option ? 'selected' : '' ?>><?= e($option) ?></option><?php endforeach; ?></select><input name="yield_amount[]" type="number" min="0.01" step="0.01" value="<?= e($yield['yield_t_ha']) ?>" aria-label="Rendement en tonnes par hectare" placeholder="t/ha"><button class="row-remove" type="button" aria-label="Supprimer cette saison">×</button></div>
              <?php endforeach; ?></div><button class="button button-outline button-small" id="addYieldRow" type="button">+ Ajouter une saison passée</button><button class="button button-green" type="submit">Enregistrer mes rendements <span>→</span></button></form>
          </article>
        </div>
      </section>
      <?php if ($personalPredictions): ?><section class="personal-prediction"><div class="eyebrow"><span class="eyebrow-line"></span> VOTRE MODÈLE PERSONNEL</div><h2>Un repère calculé à partir de <em>vos saisons.</em></h2><p>Projection : votre moyenne historique ajustée selon le rapport entre le scénario de zone sélectionné et la moyenne fictive de cette zone. À interpréter avec prudence.</p><div class="personal-results"><?php foreach ($personalPredictions as $estimate): ?><article class="personal-result"><span><?= e($estimate['crop']) ?></span><strong><?= e(number_format($estimate['projection'], 1, ',', ' ')) ?> <small>t/ha</small></strong><small>Moyenne de <?= e($estimate['count']) ?> saison(s) : <?= e(number_format($estimate['history'], 1, ',', ' ')) ?> t/ha</small></article><?php endforeach; ?></div></section><?php else: ?><section class="personal-prompt"><span>✳</span><div><strong>Votre modèle personnel est facultatif.</strong><p>Renseignez votre profil et une ou plusieurs récoltes passées pour débloquer une projection par culture.</p></div><a href="#profil" class="text-link">Compléter mes informations →</a></section><?php endif; ?>
    </main>

  <?php else: ?>
    <main class="dashboard admin-dashboard">
      <section class="admin-heading" id="pilotage"><div><div class="eyebrow"><span class="eyebrow-line"></span> ESPACE TECHNIQUE PRIVÉ · YAATAL</div><h1>Le terrain,<br><em>les équipes, les flux.</em></h1><p>Cette interface est réservée à l’équipe YAATAL. Les clients n’y ont pas accès.</p></div><div class="admin-photo"></div></section>
      <section class="admin-stats"><article><span>Producteurs partenaires</span><strong><?= e(count(array_filter($producers, static fn(array $p): bool => (int) $p['active'] === 1))) ?></strong><small>Contacts gérés par YAATAL</small></article><article><span>Produits au catalogue</span><strong><?= e(count(array_filter($products, static fn(array $p): bool => (int) $p['is_available'] === 1))) ?></strong><small>Disponibilités pilotées en interne</small></article><article><span>Bons fournisseur</span><strong><?= e(count($supplierOrders)) ?></strong><small>Achats YAATAL séparés des commandes clients</small></article><article><span>Rendez-vous qualité</span><strong><?= e(count($qualityVisits)) ?></strong><small>Contrôle avant validation du bon</small></article></section>

      <section class="admin-section" id="producteurs"><div class="section-title"><div><div class="eyebrow"><span class="eyebrow-line"></span> RÉSEAU TERRAIN</div><h2>Producteurs <em>partenaires.</em></h2><p>Coordonnées et cultures : informations visibles uniquement dans cet espace privé.</p></div></div>
        <div class="table-wrap"><table><thead><tr><th>Producteur</th><th>Contact direct</th><th>Localité / zone</th><th>Produits suivis</th><th>Statut</th><th>Action</th></tr></thead><tbody><?php foreach ($producers as $partner): ?><tr><td><strong><?= e($partner['name']) ?></strong></td><td><a href="tel:<?= e($partner['phone']) ?>"><?= e($partner['phone']) ?></a></td><td><?= e($partner['village']) ?><small><?= e($partner['zone_group']) ?></small></td><td><?= e($partner['product_names'] ?: 'Aucun produit au catalogue') ?></td><td><span class="status-badge <?= $partner['active'] ? 'status-delivered' : 'status-pending' ?>"><?= $partner['active'] ? 'Partenaire actif' : 'À vérifier' ?></span></td><td><form method="post"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="toggle_producer"><input type="hidden" name="producer_id" value="<?= e($partner['id']) ?>"><button class="button button-outline button-small" type="submit"><?= $partner['active'] ? 'Suspendre' : 'Activer' ?></button></form></td></tr><?php endforeach; ?></tbody></table></div>
        <details class="admin-details"><summary>Ajouter un producteur partenaire</summary><form method="post" class="inline-form"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="create_producer"><label>Nom<input name="name" required></label><label>Téléphone<input name="phone" required></label><label>Village / localité<input name="village" required></label><label>Zone<select name="zone_group"><option>Niayes</option><option>Vallée du fleuve</option></select></label><button class="button button-green button-small" type="submit">Ajouter le contact</button></form></details>
      </section>

      <section class="admin-section" id="approvisionnements"><div class="section-title"><div><div class="eyebrow"><span class="eyebrow-line"></span> CHAÎNE D’APPROVISIONNEMENT</div><h2>Les achats <em>de YAATAL.</em></h2><p>Les précommandes des clients servent à estimer la demande ; les bons fournisseur ci-dessous sont les achats distincts engagés par YAATAL.</p></div></div>
        <div class="split-panels">
          <article class="panel"><div class="panel-heading"><span class="panel-icon">🧺</span><div><h3>Demandes clients groupées</h3><p>Volume demandé par date, référence et producteur</p></div></div>
            <?php if (!$groupedDemand): ?><p class="empty-inline">Aucune précommande client pour le moment.</p><?php else: ?><div class="demand-list"><?php foreach ($groupedDemand as $demand): ?><div><strong><?= e($demand['product_name']) ?></strong><span><?= e($demand['producer_name']) ?> · <?= e($demand['label']) ?></span><b><?= e(number_format((float) $demand['quantity'], 0, ',', ' ')) ?> <?= e($demand['unit']) ?></b></div><?php endforeach; ?></div><?php endif; ?>
          </article>
          <article class="panel"><div class="panel-heading"><span class="panel-icon">🚚</span><div><h3>Créer un bon fournisseur YAATAL</h3><p>Un bon distinct par producteur, produit et ravitaillement</p></div></div>
            <form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="create_supplier_order"><label>Producteur partenaire<select name="producer_id" required><option value="">Sélectionner</option><?php foreach ($producers as $partner): if ($partner['active']): ?><option value="<?= e($partner['id']) ?>"><?= e($partner['name']) ?> · <?= e($partner['village']) ?></option><?php endif; endforeach; ?></select></label><label>Produit de ce producteur<select name="product_id" required><option value="">Sélectionner</option><?php foreach ($products as $item): if ($item['is_available'] && $item['producer_active']): ?><option value="<?= e($item['id']) ?>"><?= e($item['producer_name']) ?> · <?= e($item['name']) ?></option><?php endif; endforeach; ?></select></label><label>Ravitaillement<select name="delivery_id" required><option value="">Sélectionner</option><?php foreach ($adminDeliveries as $delivery): if ($delivery['status'] === 'open' && strtotime($delivery['delivery_at']) > time()): ?><option value="<?= e($delivery['id']) ?>"><?= e($delivery['label']) ?> · <?= e(dateFr($delivery['delivery_at'])) ?></option><?php endif; endforeach; ?></select></label><label>Quantité à acheter<input type="number" name="quantity" min="0.01" step="0.01" required placeholder="kg / unités"></label><button class="button button-green" type="submit">Créer le bon à contrôler <span>→</span></button></form>
          </article>
        </div>
        <div class="table-wrap"><table><thead><tr><th>Bon YAATAL</th><th>Producteur / contact</th><th>Produit</th><th>Ravitaillement</th><th>Quantité</th><th>Statut</th></tr></thead><tbody><?php if (!$supplierOrders): ?><tr><td colspan="6">Aucun bon fournisseur créé. Les bons YAATAL n’existent pas tant qu’un responsable ne les a pas saisis.</td></tr><?php endif; ?><?php foreach ($supplierOrders as $order): ?><tr><td>#YA-<?= e($order['id']) ?></td><td><?= e($order['producer_name']) ?><small><?= e($order['phone']) ?> · <?= e($order['village']) ?></small></td><td><?= e($order['product_name']) ?></td><td><?= e($order['delivery_label']) ?><small><?= e(dateFr($order['delivery_at'])) ?></small></td><td><?= e(number_format((float) $order['quantity'], 0, ',', ' ')) ?> <?= e($order['unit']) ?></td><td><span class="status-badge status-pending"><?= e($order['status']) ?></span></td></tr><?php endforeach; ?></tbody></table></div>
      </section>

      <section class="admin-section" id="qualite"><div class="section-title"><div><div class="eyebrow"><span class="eyebrow-line"></span> CONTRÔLE AVANT VALIDATION</div><h2>La qualité <em>au champ.</em></h2><p>Planifiez le passage du contrôleur YAATAL. Un bon fournisseur n’est validé qu’après un résultat favorable.</p></div></div>
        <div class="split-panels">
          <article class="panel"><div class="panel-heading"><span class="panel-icon">✓</span><div><h3>Prendre un rendez-vous</h3><p>Contrôle de la récolte chez le producteur</p></div></div>
            <form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="schedule_quality"><label>Bon fournisseur à contrôler<select name="supplier_order_id" required><option value="">Sélectionner un bon non validé</option><?php foreach ($supplierOrders as $order): if (!in_array($order['status'], ['validée', 'bloquée'], true)): ?><option value="<?= e($order['id']) ?>">#YA-<?= e($order['id']) ?> · <?= e($order['producer_name']) ?> · <?= e($order['product_name']) ?></option><?php endif; endforeach; ?></select></label><label>Date et heure sur place<input type="datetime-local" name="scheduled_at" required></label><label>Points à contrôler<textarea name="notes" required placeholder="Maturité, calibre, état sanitaire, quantité…"></textarea></label><button class="button button-green" type="submit">Programmer le passage YAATAL <span>→</span></button></form>
          </article>
          <article class="panel"><div class="panel-heading"><span class="panel-icon">🧑🏾‍🌾</span><div><h3>Rendez-vous et décisions</h3><p>Consignez le résultat avant de valider l’achat</p></div></div>
            <?php if (!$qualityVisits): ?><p class="empty-inline">Aucun rendez-vous prévu. Créez d’abord un bon fournisseur, puis planifiez un contrôle.</p><?php endif; ?>
            <div class="quality-list"><?php foreach ($qualityVisits as $visit): ?><article class="quality-card"><div><strong><?= e($visit['producer_name']) ?> · <?= e($visit['product_name']) ?></strong><span><?= e($visit['village']) ?> · <?= e(dateFr($visit['scheduled_at'], true)) ?></span><small><?= e($visit['notes']) ?></small></div><span class="status-badge <?= $visit['status'] === 'favorable' ? 'status-delivered' : ($visit['status'] === 'défavorable' ? 'status-cancelled' : 'status-pending') ?>"><?= e($visit['status']) ?></span><?php if ($visit['status'] === 'planifiée'): ?><form method="post" class="result-actions"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="quality_result"><input type="hidden" name="visit_id" value="<?= e($visit['id']) ?>"><button class="button button-outline button-small" name="outcome" value="défavorable">Non conforme</button><button class="button button-green button-small" name="outcome" value="favorable">Contrôle favorable</button></form><?php endif; ?></article><?php endforeach; ?></div>
          </article>
        </div>
      </section>

      <section class="admin-section" id="catalogue-interne"><div class="section-title"><div><div class="eyebrow"><span class="eyebrow-line"></span> OFFRE COMMERCIALE YAATAL</div><h2>Produits, prix et <em>transport.</em></h2><p>Configurez le montant producteur, la commission YAATAL et le forfait transport fixe par unité. Le client ne voit que leur total.</p></div></div>
        <div class="table-wrap"><table><thead><tr><th>Produit</th><th>Producteur partenaire</th><th>Part producteur</th><th>Commission</th><th>Transport fixe</th><th>Prix client TTC</th><th>Stock</th></tr></thead><tbody><?php foreach ($products as $item): ?><tr><td><strong><?= e($item['name']) ?></strong><small><?= e($item['category']) ?> · /<?= e($item['unit']) ?></small></td><td><?= e($item['producer_name']) ?></td><td><?= e(money($item['producer_price'])) ?></td><td><?= e(money($item['commission'])) ?></td><td><?= e(money($item['transport_fee'])) ?></td><td><strong><?= e(money((int) $item['producer_price'] + (int) $item['commission'] + (int) $item['transport_fee'])) ?></strong></td><td><?= e(number_format((float) $item['available_quantity'], 0, ',', ' ')) ?> <?= e($item['unit']) ?> <?= !$item['is_available'] ? '· masqué' : '' ?></td></tr><?php endforeach; ?></tbody></table></div>
        <details class="admin-details"><summary>Ajouter ou mettre à jour un produit partenaire</summary><form method="post" class="inline-form product-admin-form"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="save_product"><label>Produit existant (optionnel)<select name="product_id"><option value="">Nouveau produit</option><?php foreach ($products as $item): ?><option value="<?= e($item['id']) ?>"><?= e($item['name']) ?> · <?= e($item['producer_name']) ?></option><?php endforeach; ?></select></label><label>Producteur<select name="producer_id" required><option value="">Sélectionner</option><?php foreach ($producers as $partner): if ($partner['active']): ?><option value="<?= e($partner['id']) ?>"><?= e($partner['name']) ?></option><?php endif; endforeach; ?></select></label><label>Nom du produit<input name="name" required></label><label>Catégorie<input name="category" required placeholder="Ex. : Tomate"></label><label>Unité<select name="unit"><option>kg</option><option>pièce</option><option>botte</option><option>caisse</option></select></label><label>Prix producteur / unité<input type="number" name="producer_price" min="0" required></label><label>Commission YAATAL / unité<input type="number" name="commission" min="0" required></label><label>Transport fixe / unité<input type="number" name="transport_fee" min="0" required></label><label>Stock disponible<input type="number" name="available_quantity" min="0" step="0.01" required></label><label>Photo (URL)<input type="url" name="image_url" placeholder="https://…"></label><button class="button button-green button-small" type="submit">Enregistrer le produit</button></form>
          <div class="product-visibility"><?php foreach ($products as $item): ?><form method="post"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="toggle_product"><input type="hidden" name="product_id" value="<?= e($item['id']) ?>"><span><?= e($item['name']) ?> · <?= $item['is_available'] ? 'visible client' : 'masqué client' ?></span><button class="button button-outline button-small" type="submit"><?= $item['is_available'] ? 'Masquer' : 'Rendre disponible' ?></button></form><?php endforeach; ?></div>
        </details>
      </section>

      <section class="admin-section"><div class="section-title"><div><div class="eyebrow"><span class="eyebrow-line"></span> CALENDRIER DES CAMIONS</div><h2>Programmer un <em>ravitaillement.</em></h2><p>Les clients choisissent une date et commandent avant sa clôture. Les équipes YAATAL assurent la livraison.</p></div></div>
        <div class="split-panels">
          <article class="panel"><div class="panel-heading"><span class="panel-icon">🗓</span><div><h3>Dates publiées</h3><p>Dates visibles sur l’espace client</p></div></div><div class="delivery-list"><?php foreach ($adminDeliveries as $delivery): ?><div><strong><?= e($delivery['label']) ?> · <?= e($delivery['region']) ?></strong><span>Livraison <?= e(dateFr($delivery['delivery_at'], true)) ?> · clôture <?= e(dateFr($delivery['cutoff_at'], true)) ?></span><small>Quota logistique : <?= e($delivery['target_kg']) ?> kg · <?= e($delivery['status']) ?></small></div><?php endforeach; ?></div></article>
          <article class="panel"><div class="panel-heading"><span class="panel-icon">📍</span><div><h3>Ouvrir une nouvelle date</h3><p>Définie par YAATAL selon le transport disponible</p></div></div><form method="post" class="form-grid"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="create_delivery"><label>Nom de la collecte<input name="label" required placeholder="Ex. : Ravitaillement Thiès — semaine 24"></label><label>Zone desservie<input name="region" required placeholder="Thiès et environs"></label><label>Date et heure de livraison YAATAL<input type="datetime-local" name="delivery_at" required></label><label>Date limite de commande<input type="datetime-local" name="cutoff_at" required></label><label>Quota logistique (kg)<input type="number" name="target_kg" min="1" required></label><button class="button button-green" type="submit">Publier la date de ravitaillement <span>→</span></button></form></article>
        </div>
      </section>

      <section class="admin-section"><div class="section-title"><div><div class="eyebrow"><span class="eyebrow-line"></span> COMMANDES DES CLIENTS YAATAL</div><h2>À livrer <em>par nos équipes.</em></h2><p>Ce sont les demandes des commerçants, distinctes des bons d’achat de YAATAL auprès des producteurs.</p></div></div>
        <div class="table-wrap"><table><thead><tr><th>Référence / client</th><th>Date choisie</th><th>Adresse de livraison</th><th>Montant client</th><th>État YAATAL</th><th>Mettre à jour</th></tr></thead><tbody><?php if (!$adminOrders): ?><tr><td colspan="6">Aucune précommande client enregistrée.</td></tr><?php endif; ?><?php foreach ($adminOrders as $order): ?><tr><td><strong><?= e($order['reference']) ?></strong><small><?= e($order['client_name']) ?> · <?= e($order['business_type']) ?> · <?= e($order['phone']) ?></small></td><td><?= e($order['delivery_label']) ?><small><?= e(dateFr($order['delivery_at'])) ?></small></td><td><?= e($order['delivery_address']) ?></td><td><?= e(money($order['total'])) ?></td><td><span class="status-badge status-<?= e($order['status']) ?>"><?= e($statusNames[$order['status']] ?? $order['status']) ?></span></td><td><?php if ($order['status'] !== 'delivered' && $order['status'] !== 'cancelled'): ?><form method="post" class="status-form"><input type="hidden" name="_csrf" value="<?= e($csrf) ?>"><input type="hidden" name="action" value="update_client_order"><input type="hidden" name="order_id" value="<?= e($order['id']) ?>"><select name="status"><option value="confirmed">Confirmer</option><option value="preparing">Préparer</option><option value="delivered">Livrée par YAATAL</option><option value="cancelled">Annuler</option></select><button class="button button-green button-small" type="submit">Enregistrer</button></form><?php endif; ?></td></tr><?php endforeach; ?></tbody></table></div>
      </section>
      <section class="admin-section"><div class="section-title"><div><div class="eyebrow"><span class="eyebrow-line"></span> RETOURS APRÈS LIVRAISON</div><h2>Écouter les <em>commerçants.</em></h2></div></div><?php if (!$feedbackRows): ?><p class="empty-inline">Les avis des clients s’afficheront ici après la livraison par YAATAL.</p><?php else: ?><div class="feedback-admin-list"><?php foreach ($feedbackRows as $feedback): ?><article><strong><?= e($feedback['reference']) ?> · <?= e($feedback['client_name']) ?> · <?= e($feedback['rating']) ?>/5</strong><span><?= e($feedback['issue']) ?></span><p><?= e($feedback['message']) ?></p></article><?php endforeach; ?></div><?php endif; ?></section>
    </main>
  <?php endif; ?>
  <footer class="site-footer"><a class="brand footer-brand" href="index.php?page=<?= e(roleHome($currentUser['role'])) ?>"><span class="brand-mark" aria-hidden="true"><span></span><span></span></span><span class="brand-name">yaatal<small>DU PRODUCTEUR AU MARCHÉ</small></span></a><p>Commandes groupées, qualité contrôlée et livraison par YAATAL.</p><span>© YAATAL · Données démo à configurer avant déploiement.</span></footer>
<?php endif; ?>
<script src="app.js"></script>
</body>
</html>
