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
