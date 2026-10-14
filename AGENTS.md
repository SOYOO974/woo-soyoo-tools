# Directives pour Agents Antigravity — Woo SOYOO Tools (`woo-soyoo-tools`)

## 🚫 PROTOCOLE DE DÉPLOIEMENT : ZÉRO DÉPLOIEMENT FTP PAR DÉFAUT

> [!CRITICAL]
> **INTERDICTION FORMELLE DE DÉPLOYER PAR FTP / SFTP OU DE PROPOSER DES TABLEAUX RÉCAPITULATIFS FTP SANS DEMANDE EXPLICITE DE JULIEN.**
>
> Cette extension in-house mutualisée est hébergée sur GitHub (`SOYOO974/woo-soyoo-tools`) et intègre **Plugin Update Checker (PUC v5)** configuré pour surveiller les assets de release GitHub.
> Les sites WordPress/WooCommerce clients gérés par SOYOO se mettent à jour **automatiquement en 1 clic** via le tableau de bord WordPress dès qu'une nouvelle release GitHub est publiée.

---

## 📋 GOUVERNANCE OBLIGATOIRE DES FONCTIONNALITÉS (`FEATURES_CONTEXT.md`)

Chaque amélioration WooCommerce implémentée dans `woo-soyoo-tools` doit **obligatoirement** respecter ces 4 règles :

1. **Isolation Modulaire** :
   - Créer une classe dédiée dans `includes/features/class-feature-<slug>.php` (namespace `SOYOO\Tools\Features`) exposant une méthode statique `register()`.
2. **Déclaration dans le Registre Central** :
   - Enregistrer la fonctionnalité dans [`includes/class-feature-manager.php`](file:///c:/Antigravity/woo-plugins/woo-soyoo-tools/includes/class-feature-manager.php) (`register_built_in_features()`) avec :
     - `id`, `title`, `category`, `category_slug`, `badge`, `default_enabled` (`true` par défaut pour les améliorations transversales), `since`, `summary`, `problem`, `solution`, `hooks`, `callback`.
3. **Persistance Opt-Out Automatique** :
   - `Feature_Manager` stocke uniquement les états explicites dans `woo_soyoo_tools_features_state`. Ainsi, toute nouvelle fonctionnalité déclarée avec `'default_enabled' => true` dans une mise à jour du plugin **s'active automatiquement** sur tous les sites existants lors de la mise à jour, tout en respectant les fonctionnalités qu'un site aurait explicitement décochées dans **WooCommerce > SOYOO Tools**.
4. **Mise à jour Systématique de `FEATURES_CONTEXT.md`** :
   - Toute création ou modification d'une fonctionnalité DOIT être immédiatement documentée dans [`FEATURES_CONTEXT.md`](file:///c:/Antigravity/woo-plugins/woo-soyoo-tools/FEATURES_CONTEXT.md) (tableau récapitulatif + section détaillée : symptôme, cause racine et architecture technique).

---

## 🔄 WORKFLOW DE PUBLICATION & MISE À JOUR PAR DÉFAUT

Dès qu'une fonctionnalité, correction de bug ou amélioration est apportée à cette extension :

1. **Incrémentation de Version** :
   - Mettre à jour `Version: X.Y.Z` dans l'en-tête de [`woo-soyoo-tools.php`](file:///c:/Antigravity/woo-plugins/woo-soyoo-tools/woo-soyoo-tools.php).
   - Mettre à jour la constante `WOO_SOYOO_TOOLS_VERSION` dans [`woo-soyoo-tools.php`](file:///c:/Antigravity/woo-plugins/woo-soyoo-tools/woo-soyoo-tools.php).
2. **Mise à jour de [`FEATURES_CONTEXT.md`](file:///c:/Antigravity/woo-plugins/woo-soyoo-tools/FEATURES_CONTEXT.md)**.
3. **Validation Syntaxique & Build de l'Archive (.zip)** :
   - Exécuter le script de build standard (qui vérifie `php -l` sur tous les fichiers PHP et génère `woo-soyoo-tools.zip` avec des forward slashes `/` compatibles Linux) :
     ```powershell
     powershell -ExecutionPolicy Bypass -File .\bin\build-zip.ps1
     ```
4. **Commit & Push Proactif sur GitHub** :
   ```bash
   git add .
   git commit -m "feat/fix: <description explicite> (vX.Y.Z)"
   git push origin main
   ```
5. **Publication Proactive de la Release GitHub** :
   ```bash
   gh release create vX.Y.Z woo-soyoo-tools.zip --title "vX.Y.Z - <Titre court>" --notes "<Changelog formaté>"
   ```

---

## 🧱 ARCHITECTURE DU PLUGIN

- [`woo-soyoo-tools.php`](file:///c:/Antigravity/woo-plugins/woo-soyoo-tools/woo-soyoo-tools.php) : Point d'entrée, déclaration HPOS (`custom_order_tables`) & `product_instance_caching`, configuration `plugin-update-checker` v5 et initialisation.
- [`FEATURES_CONTEXT.md`](file:///c:/Antigravity/woo-plugins/woo-soyoo-tools/FEATURES_CONTEXT.md) : Source de vérité documentaire détaillant chaque fonctionnalité implémentée dans le plugin.
- [`includes/class-feature-manager.php`](file:///c:/Antigravity/woo-plugins/woo-soyoo-tools/includes/class-feature-manager.php) : Registre central des fonctionnalités, gestionnaire d'état Opt-Out (`woo_soyoo_tools_features_state`) et exécution modulaire.
- [`includes/class-tools-admin.php`](file:///c:/Antigravity/woo-plugins/woo-soyoo-tools/includes/class-tools-admin.php) : Interface d'administration **WooCommerce > SOYOO Tools** (recherche instantanée, filtres par catégorie et statut, interrupteurs AJAX instantanés, tiroir de contexte technique et barrière anti-notices).
- [`includes/features/class-feature-force-french-emails.php`](file:///c:/Antigravity/woo-plugins/woo-soyoo-tools/includes/features/class-feature-force-french-emails.php) : Verrouillage à 3 niveaux de la locale française sur les e-mails transactionnels et administrateurs WooCommerce.
