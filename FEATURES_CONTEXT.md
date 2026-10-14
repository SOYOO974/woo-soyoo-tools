# Catalogue & Contexte des Fonctionnalités — Woo SOYOO Tools (`woo-soyoo-tools`)

Ce fichier de contexte centralise et documente de manière exhaustive **chaque fonctionnalité et amélioration transversale** implémentée dans le plugin `woo-soyoo-tools`.

> [!IMPORTANT]
> **Règle de gouvernance & Mise à jour automatique obligatoire (`plugin-update-checker`)** :
> Dès qu'une nouvelle fonctionnalité est implémentée ou qu'une fonctionnalité existante est modifiée dans `woo-soyoo-tools`, il est **impératif** d'exécuter les 5 étapes suivantes sans attendre de consigne :
> 1. **Isolation modulaire** : Créer/mettre à jour la classe sous `includes/features/class-feature-<slug>.php`.
> 2. **Registre central** : Déclarer la fonctionnalité dans [`includes/class-feature-manager.php`](file:///c:/Antigravity/woo-plugins/woo-soyoo-tools/includes/class-feature-manager.php) pour qu'elle apparaisse dans **WooCommerce > SOYOO Tools** (filtrable et désactivable par site).
> 3. **Documentation de contexte** : Documenter la fonctionnalité dans ce fichier `FEATURES_CONTEXT.md` (ID, catégorie, état par défaut, problème résolu, cause racine et architecture technique).
> 4. **Incrémentation de version & Build ZIP** : Incrémenter `Version: X.Y.Z` et `WOO_SOYOO_TOOLS_VERSION` dans [`woo-soyoo-tools.php`](file:///c:/Antigravity/woo-plugins/woo-soyoo-tools/woo-soyoo-tools.php), puis compiler l'archive `woo-soyoo-tools.zip` via :
>    ```powershell
>    powershell -ExecutionPolicy Bypass -File .\bin\build-zip.ps1
>    ```
> 5. **Commit, Push & Création systématique de la Release GitHub (Auto-Update PUC v5)** : Pousser sur `origin/main` et **toujours créer la release GitHub correspondante avec l'asset `woo-soyoo-tools.zip` attaché** pour déclencher la mise à jour automatique via `plugin-update-checker` sur tous les sites WooCommerce clients :
>    ```bash
>    git add . && git commit -m "feat: <description> (vX.Y.Z)" && git push origin main
>    gh release create vX.Y.Z woo-soyoo-tools.zip --title "vX.Y.Z - <Titre>" --notes "<Changelog>"
>    ```

---

## Vue d'ensemble des fonctionnalités

| ID (`slug`) | Titre | Catégorie | Par défaut | Depuis | Fichier d'implémentation |
| :--- | :--- | :--- | :---: | :---: | :--- |
| `force_french_transactional_emails` | Verrouillage de la langue française des e-mails de commande (Action Scheduler & Webhooks) | E-mails & Notifications | **Activée** (`true`) | `v1.0.0` | [`includes/features/class-feature-force-french-emails.php`](file:///c:/Antigravity/woo-plugins/woo-soyoo-tools/includes/features/class-feature-force-french-emails.php) |
| `link_guest_orders_to_customers` | Rattachement automatique des commandes invités aux comptes clients (HPOS & Temps réel) | Commandes & Clients | **Activée** (`true`) | `v1.1.0` | [`includes/features/class-feature-link-guest-orders.php`](file:///c:/Antigravity/woo-plugins/woo-soyoo-tools/includes/features/class-feature-link-guest-orders.php) |
| `database_and_scheduler_hygiene` | Hygiène Base de Données, Action Scheduler, Transients & Options Autoload | Performance & Base de données | **Activée** (`true`) | `v1.1.0` | [`includes/features/class-feature-database-hygiene.php`](file:///c:/Antigravity/woo-plugins/woo-soyoo-tools/includes/features/class-feature-database-hygiene.php) |
| `abandoned_orders_cleanup_1y` | Purge nocturne des commandes abandonnées ou échouées de plus d'un an (HPOS-Safe) | Performance & Base de données | **Activée** (`true`) | `v1.1.0` | [`includes/features/class-feature-abandoned-orders-cleanup.php`](file:///c:/Antigravity/woo-plugins/woo-soyoo-tools/includes/features/class-feature-abandoned-orders-cleanup.php) |
| `admin_login_geo_alert` | Alerte de sécurité Geo-IP asynchrone sur les connexions Administrateur | Sécurité & Surveillance | **Activée** (`true`) | `v1.1.0` | [`includes/features/class-feature-admin-login-alert.php`](file:///c:/Antigravity/woo-plugins/woo-soyoo-tools/includes/features/class-feature-admin-login-alert.php) |
| `safe_admin_svg_uploads` | Téléversement SVG sécurisé pour Administrateurs & Gestionnaires de boutique | Back-Office & Médias | **Activée** (`true`) | `v1.1.0` | [`includes/features/class-feature-safe-svg-uploads.php`](file:///c:/Antigravity/woo-plugins/woo-soyoo-tools/includes/features/class-feature-safe-svg-uploads.php) |

---

## Détail des fonctionnalités

### 1. `force_french_transactional_emails` — Verrouillage de la langue française des e-mails de commande (Action Scheduler & Webhooks)

- **Catégorie** : `E-mails & Notifications` (`emails`)
- **Impact** : `Critique`
- **État par défaut** : Activée (`true`)
- **Introduite en** : `v1.0.0`
- **Classe PHP** : [`\SOYOO\Tools\Features\Feature_Force_French_Emails`](file:///c:/Antigravity/woo-plugins/woo-soyoo-tools/includes/features/class-feature-force-french-emails.php)

#### Symptôme & Problème observé
Sur les boutiques WooCommerce où l'envoi des e-mails transactionnels est différé en tâche de fond via Action Scheduler (`add_filter( 'woocommerce_defer_transactional_emails', '__return_true' )` — standard de performance SOYOO Rocket.net pour accélérer le checkout) ou lorsque le changement de statut d'une commande est déclenché par un webhook de passerelle de paiement externe (Stripe, PayPlug, Alma, PayPal, Systempay), les e-mails de notification de **Nouvelle commande** (`admin-new-order.php`) reçus par les gestionnaires de boutique arrivent aléatoirement en **anglais** (`New order #1234`, `Product`, `Quantity`, `Price`).

#### Analyse des 3 causes racines dans WooCommerce Core
1. **Faux-ami sur le nom du hook Action Scheduler** :
   - De nombreux snippets en ligne suggèrent de se brancher sur `add_action( 'woocommerce_send_queued_transactional_email', ... )`.
   - Or, dans le cœur de WooCommerce (`includes/class-wc-emails.php`), **l'action `woocommerce_send_queued_transactional_email` n'existe pas** : `WC_Emails::send_queued_transactional_email` est le nom de la *méthode PHP* appelée par le hook Action Scheduler **`woocommerce_deliver_queued_transactional_email`** (à la priorité `10`). Se brancher uniquement sur `woocommerce_send_queued_transactional_email` est donc inopérant sur WooCommerce standard.
2. **Instanciation précoce de `WC_Emails::instance()` avant le changement de locale** :
   - Dès que `WC_Emails::instance()` (`WC()->mailer()`) est appelé pour la première fois dans un processus (ex: worker Action Scheduler, WP-Cron ou Webhook en locale `en_US`), les constructeurs `WC_Email_New_Order::__construct()`, `WC_Email_Cancelled_Order::__construct()` et `WC_Email_Failed_Order::__construct()` s'exécutent immédiatement et figent en mémoire les chaînes par défaut en anglais (`[{site_title}]: New order #{order_number}`, `New order: #{order_number}`).
   - Si le `switch_to_locale('fr_FR')` intervient *après* l'instanciation de `WC_Emails`, le sujet et l'en-tête restent en anglais.
3. **Exclusion native des e-mails Administrateur dans `WC_Email::setup_locale()`** :
   - Dans `includes/emails/class-wc-email.php`, la méthode native `WC_Email::setup_locale()` contient :
     ```php
     if ( $this->is_customer_email() && apply_filters( 'woocommerce_email_setup_locale', true ) ) {
         wc_switch_to_site_locale();
     }
     ```
   - Pour les e-mails destinés aux gestionnaires de boutique (`WC_Email_New_Order`, `WC_Email_Cancelled_Order`, `WC_Email_Failed_Order`), `$this->is_customer_email()` vaut **`false`**. WooCommerce ne bascule donc **jamais** sur la locale du site pour les e-mails administrateurs ! Si le worker Action Scheduler, le webhook de paiement ou le profil d'un administrateur tourne sous une locale anglaise, l'e-mail part en anglais.

#### Architecture de la solution SOYOO (Verrouillage à 3 niveaux)
1. **Niveau 1 — Interception Action Scheduler avant instanciation (`priorité 1`)** :
   - Branchement sur `woocommerce_deliver_queued_transactional_email` (priorité `1` à l'ouverture, `99` à la restauration), sur le filtre `woocommerce_allow_send_queued_transactional_email` (priorité `1`, exécuté juste avant `WC_Emails::instance()`), ainsi que sur `woocommerce_send_queued_transactional_email` (compatibilité snippets tiers).
   - Détermination de la locale cible via `get_option( 'WPLANG' )` (avec repli strict sur `'fr_FR'` si `WPLANG` est vide ou `en_*`).
   - Pile de sécurité interne (`$switch_depth`) garantissant que chaque `restore_previous_locale()` correspond exactement à un `switch_to_locale()` réussi.
2. **Niveau 2 — Couverture universelle des notifications & Webhooks (Clients + Gestionnaires)** :
   - Enveloppement automatique à priorité `1` et `999` de toutes les actions listées dans `woocommerce_email_actions` (`*_notification`), des filtres `woocommerce_email_setup_locale` / `woocommerce_email_restore_locale` et des actions de renvoi manuel (`woocommerce_before_resend_order_emails` / `woocommerce_after_resend_order_emails`).
3. **Niveau 3 — Rechargement garanti du Textdomain `woocommerce` & Garde-fou Sujets/En-têtes Admin** :
   - Vérifie si la chaîne témoin `__( 'New order', 'woocommerce' )` est bien traduite après la bascule de locale. Si le domaine `woocommerce` avait déjà été chargé en `en_US` plus tôt dans la requête (cas fréquent sous WP-Cron / CLI), force `unload_textdomain( 'woocommerce', true )` puis `WC()->load_plugin_textdomain()` / `load_textdomain()` depuis `WP_LANG_DIR . '/plugins/woocommerce-fr_FR.mo'`.
   - Filtre `woocommerce_email_subject_{new_order|cancelled_order|failed_order}` et `woocommerce_email_heading_{new_order|cancelled_order|failed_order}` pour ré-évaluer dynamiquement les sujets et en-têtes par défaut s'ils avaient été construits en anglais avant la bascule.

---

### 2. `link_guest_orders_to_customers` — Rattachement automatique des commandes invités aux comptes clients (HPOS & Temps réel)

- **Catégorie** : `Commandes & Clients` (`orders`)
- **Badge** : `Essentiel`
- **État par défaut** : Activée (`true`)
- **Introduite en** : `v1.1.0`
- **Classe PHP** : [`\SOYOO\Tools\Features\Feature_Link_Guest_Orders`](file:///c:/Antigravity/woo-plugins/woo-soyoo-tools/includes/features/class-feature-link-guest-orders.php)

#### Symptôme & Problème observé
Les clients possédant déjà un compte sur la boutique mais commandant en mode invité (paiement express Apple Pay / Google Pay, achat rapide sur mobile sans se connecter) voient leurs commandes enregistrées avec `customer_id = 0`. Ces commandes n'apparaissent pas dans leur espace « Mon compte » et fragmentent les statistiques LTV client. De même, lorsqu'un client crée son compte *après* avoir passé une ou plusieurs commandes en invité, son historique antérieur n'est pas systématiquement rattaché.

#### Architecture de la solution SOYOO (HPOS & Zéro Requête Superflue)
1. **Rattachement rétroactif à l'inscription (`woocommerce_created_customer`, prio 10)** :
   - Appelle la fonction native `wc_update_new_customer_past_orders( $customer_id )` dès la création d'un compte client pour relier toutes les commandes invités passées partageant le même e-mail de facturation.
2. **Affectation à la volée au Checkout (`woocommerce_checkout_create_order`, prio 20)** :
   - Si `(int) $order->get_customer_id() === 0` et que `$order->get_billing_email()` correspond à un utilisateur WordPress existant (`get_user_by( 'email', $email )`), appelle directement `$order->set_customer_id( $user->ID )` sur l'objet `WC_Order` en mémoire **avant** l'insertion en base (`0` requête SQL d'écriture additionnelle).
3. **Filet de sécurité hors checkout & Webhooks (`woocommerce_new_order` & `woocommerce_order_status_processing`, prio 20)** :
   - Pour les commandes créées par API REST, paiement express hors tunnel standard ou validées par webhook : si `customer_id === 0`, recherche l'utilisateur par e-mail de facturation, applique `$order->set_customer_id( $user->ID )` et exécute `$order->save()` avec un verrou statique anti-récursion par ID de commande.

---

### 3. `database_and_scheduler_hygiene` — Hygiène Base de Données, Action Scheduler, Transients & Options Autoload

- **Catégorie** : `Performance & Base de données` (`performance`)
- **Badge** : `Performance`
- **État par défaut** : Activée (`true`)
- **Introduite en** : `v1.1.0`
- **Classe PHP** : [`\SOYOO\Tools\Features\Feature_Database_Hygiene`](file:///c:/Antigravity/woo-plugins/woo-soyoo-tools/includes/features/class-feature-database-hygiene.php)

#### Symptôme & Problème observé
Sur les boutiques WooCommerce à fort trafic, les tables `wp_actionscheduler_actions` et `wp_actionscheduler_logs` accumulent des centaines de milliers de lignes (rétention par défaut de 31 jours et nettoyage natif par lots de 20 seulement). De plus, des hooks orphelins (`fetch_patterns`, `sfn_send_usage_report`, `sfn_followup_emails`, `action_scheduler/migration_hook`) s'empilent sans callback, les transients expirés gonflent `wp_options`, les événements Independent Analytics s'accumulent indéfiniment si la rétention est à `0`, et certaines extensions chargent de lourdes options en `autoload` à chaque requête PHP.

#### Architecture de la solution SOYOO (7 Volets)
1. **Réglages Action Scheduler (`prio 99`)** :
   - `action_scheduler_retention_period` ramené à `7 * DAY_IN_SECONDS` (7 jours).
   - `action_scheduler_cleanup_batch_size` porté à `1000`.
2. **Neutralisation des hooks Action Scheduler fantômes (`init`, prio 20)** :
   - Pour chaque hook orphelin connu (`fetch_patterns`, `sfn_send_usage_report`, `sfn_followup_emails`, `action_scheduler/migration_hook`), si `! has_action( $hook )`, branche `__return_true` afin qu'Action Scheduler clôture proprement les tâches sans erreur.
3. **Purge active bi-quotidienne (`soyoo_tools_as_purge_cron`)** :
   - Planification protégée par un transient témoin de 24h (`soyoo_tools_db_hygiene_cron_checked`) pour éviter d'appeler `wp_next_scheduled()` sur chaque requête front.
   - Supprime par lots bornés (`LIMIT 5000`, budget temps max `25s`, avec `wp_cache_flush_runtime()` entre chaque lot) :
     - `actionscheduler_logs` > 7 jours,
     - `actionscheduler_actions` aux statuts `complete` et `canceled` > 7 jours,
     - `actionscheduler_actions` au statut `failed` > 14 jours.
4. **Purge quotidienne douce des Transients (`soyoo_tools_transients_cleanup_cron`)** :
   - Nettoie jusqu'à `500` transients expirés (`_transient_timeout_*` + `_transient_*`) par jour dans `wp_options` sans verrouiller la table.
5. **Garde-fou Independent Analytics Pro** :
   - Force `ia_data_retention_period` à `365` jours si vide ou `0`, et purge chaque nuit par lot de `5000` les lignes de `wp_analytics_wp_events` datant de plus de 365 jours si la table est présente.
6. **Dé-autoload non-destructif (`admin_init`, transient de 3 jours)** :
   - Désactive l'autoload des options lourdes connues (`admin_site_enhancements_extra`, `wpassetcleanup_global_data`, `woocommerce_shipment_tracking_custom_providers`, `rank_math_modules`, `wf_crawlers_user_agents`, `sib_push_app_xkeysib`) via `wp_set_option_autoload( $opt, false )` (WP 6.6+) ou `autoload IN ('yes', 'on', 'auto', 'auto-on')`.
7. **Déclenchement manuel sécurisé en 1 clic** :
   - Bouton d'action rapide intégré dans la carte de la fonctionnalité (`?page=woo-soyoo-tools&soyoo_run_db_opt=1&_wpnonce=...`) exécutant immédiatement la purge complète et affichant une bannière récapitulative du nombre d'actions, logs, transients et options traités.

---

### 4. `abandoned_orders_cleanup_1y` — Purge nocturne des commandes abandonnées ou échouées de plus d'un an (HPOS-Safe)

- **Catégorie** : `Performance & Base de données` (`performance`)
- **Badge** : `Hygiène HPOS`
- **État par défaut** : Activée (`true`)
- **Introduite en** : `v1.1.0`
- **Classe PHP** : [`\SOYOO\Tools\Features\Feature_Abandoned_Orders_Cleanup`](file:///c:/Antigravity/woo-plugins/woo-soyoo-tools/includes/features/class-feature-abandoned-orders-cleanup.php)

#### Symptôme & Problème observé
Les commandes abandonnées au moment du paiement ou échouées (`cancelled`, `failed`) s'accumulent au fil des ans dans `wp_wc_orders` (HPOS) et `wp_posts`, alourdissant les tables de commandes sans aucune justification comptable puisqu'aucun encaissement ni transaction n'a jamais eu lieu.

#### Architecture de la solution SOYOO (4 Verrous de Sécurité Stricts)
1. **Planification nocturne locale (`03h30` via `wp_timezone()`)** :
   - Planifie la tâche quotidienne `soyoo_tools_abandoned_orders_cleanup_cron` à 03h30 heure locale du site (protégée par un transient témoin de 24h).
2. **Sélection HPOS-native via `wc_get_orders()`** :
   - Récupère au maximum `30` commandes par nuit (`type => 'shop_order'`, `status => ['cancelled', 'failed']`, `date_created => '<' . (time() - 365 * DAY_IN_SECONDS)`).
3. **Vérification individuelle des 4 verrous avant suppression** :
   - Verrou 1 : `in_array( $order->get_status(), array( 'cancelled', 'failed' ), true )`
   - Verrou 2 : `null === $order->get_date_paid()` (jamais payée)
   - Verrou 3 : `empty( $order->get_transaction_id() )` (aucun identifiant de transaction bancaire)
   - Verrou 4 : `$order->get_date_created()->getTimestamp() < time() - 365 * DAY_IN_SECONDS`
4. **Suppression définitive & Traçabilité** :
   - Exécute `$order->delete( true )` (compatible HPOS & Legacy) et consigne le nombre et la liste des IDs supprimés dans `wc_get_logger()` (`source: woo-soyoo-tools`).

---

### 5. `admin_login_geo_alert` — Alerte de sécurité Geo-IP asynchrone sur les connexions Administrateur

- **Catégorie** : `Sécurité & Surveillance` (`security`)
- **Badge** : `Sécurité`
- **État par défaut** : Activée (`true`)
- **Introduite en** : `v1.1.0`
- **Classe PHP** : [`\SOYOO\Tools\Features\Feature_Admin_Login_Alert`](file:///c:/Antigravity/woo-plugins/woo-soyoo-tools/includes/features/class-feature-admin-login-alert.php)

#### Symptôme & Problème observé
Toute connexion réussie à un compte `administrator` depuis un pays inhabituel doit déclencher une alerte immédiate, sans ralentir le temps de connexion de l'administrateur et sans pouvoir être contournée par un en-tête `X-Forwarded-For` forgé par l'attaquant.

#### Architecture de la solution SOYOO
1. **Extraction d'IP anti-spoofing sur `wp_login` (`prio 10`)** :
   - Ne cible que les utilisateurs disposant du rôle `administrator`.
   - Extrait l'IP exclusivement depuis `HTTP_CF_CONNECTING_IP` (fourni par Cloudflare Enterprise / Rocket.net et validé par `filter_var`) ou `REMOTE_ADDR` — **sans jamais faire confiance à `HTTP_X_FORWARDED_FOR`**.
   - Récupère également `HTTP_CF_IPCOUNTRY` s'il est présent et ignore les IPs locales (`127.0.0.1`, `::1`).
2. **Délégation asynchrone (`wp_schedule_single_event`)** :
   - Planifie `soyoo_tools_verify_admin_login_ip` à `time()` afin que la redirection `wp-login.php` soit instantanée (`0 ms` de latence réseau au login).
3. **Worker Geo-IP avec Fast-Path Cloudflare & Double API** :
   - Vérifie un verrou transient `soyoo_login_alert_<md5(ip|login)>` de 15 minutes pour éviter tout spam d'alerte.
   - Si `HTTP_CF_IPCOUNTRY` est déjà fourni et figure dans la liste autorisée `apply_filters( 'woo_soyoo_tools_allowed_admin_countries', array( 'FR', 'RE', 'PK', 'MG', 'MU', 'YT' ) )`, s'arrête immédiatement sans appel HTTP externe.
   - Sinon, interroge `http://ip-api.com/json/{ip}?fields=status,country,countryCode` avec repli automatique HTTPS sur `https://ipwho.is/{ip}`.
   - Si le pays détecté n'est pas autorisé, envoie un e-mail d'alerte à `apply_filters( 'woo_soyoo_tools_security_alert_email', 'julien@soyoo.re' )` mentionnant explicitement `get_bloginfo('name')` et `home_url()` dans le sujet et le corps, ainsi que l'identifiant, l'IP, le pays et l'horodatage local.

---

### 6. `safe_admin_svg_uploads` — Téléversement SVG sécurisé pour Administrateurs & Gestionnaires de boutique

- **Catégorie** : `Back-Office & Médias` (`admin`)
- **Badge** : `Confort Admin`
- **État par défaut** : Activée (`true`)
- **Introduite en** : `v1.1.0`
- **Classe PHP** : [`\SOYOO\Tools\Features\Feature_Safe_Svg_Uploads`](file:///c:/Antigravity/woo-plugins/woo-soyoo-tools/includes/features/class-feature-safe-svg-uploads.php)

#### Symptôme & Problème observé
WordPress bloque par défaut le téléversement de fichiers vectoriels `.svg` (logos, pictogrammes de réassurance, icônes). Installer une extension tierce dédiée uniquement au support SVG ajoute une dépendance superflue ou ouvre parfois l'upload SVG à des rôles non privilégiés.

#### Architecture de la solution SOYOO
1. **Restriction stricte par capacité (`manage_woocommerce` ou `manage_options`)** :
   - Ajoute `'svg' => 'image/svg+xml'` dans `upload_mimes` et corrige la détection MIME dans `wp_check_filetype_and_ext` (WordPress 6.x) **uniquement** si l'utilisateur courant est Administrateur ou Gestionnaire de boutique.
2. **Inspection anti-XSS sur `wp_handle_upload_prefilter`** :
   - Avant tout déplacement du fichier temporaire vers `wp-content/uploads`, inspecte le contenu brut du fichier `.svg` :
     - Rejette le fichier s'il ne contient pas de balise d'ouverture `<svg`.
     - Rejette le fichier s'il contient l'un des vecteurs XSS (`<script`, `onload=`, `onerror=`, `javascript:`, `<iframe`, `<foreignObject` ou tout attribut d'événement inline `on*=`).
3. **Rendu des miniatures dans la Médiathèque (`admin_head`)** :
   - Injecte une règle CSS légère garantissant un affichage proportionnel des fichiers `.svg` dans la grille et la liste de la médiathèque WordPress.
