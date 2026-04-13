# Projet Ansible — Déploiement Stack Web sur AWS

Automatisation complète du déploiement d'une infrastructure web (Nginx + PHP 8.1 + MySQL) sur 4 serveurs Ubuntu 22.04 hébergés sur AWS, à l'aide d'Ansible.

---

## Infrastructure

| Serveur | Rôle | IP Privée | IP Publique |
|---|---|---|---|
| Master | Contrôleur Ansible + Reverse Proxy | 10.0.1.86 | 15.222.196.252 |
| Node 1 | Serveur Web (Nginx + PHP-FPM) | 10.0.2.11 | — |
| Node 2 | Serveur Web (Nginx + PHP-FPM) | 10.0.2.12 | — |
| Node 3 | Base de données (MySQL) | 10.0.2.13 | — |
| Node 4 | Monitoring / Backup | 10.0.2.14 | — |

> Les nodes sont dans un subnet privé AWS (10.0.2.0/24). Seul le Master est accessible depuis Internet.

---

## Prérequis

- Ansible installé sur le Master (`sudo apt install -y ansible`)
- Clé SSH `williamkey` présente dans `~/.ssh/` avec les permissions correctes
- Accès SSH fonctionnel vers les 4 nodes
- Python 3 installé sur tous les nodes

```bash
# Vérifier Ansible
ansible --version

# Corriger les permissions de la clé SSH
chmod 400 ~/.ssh/williamkey
```

---

## Structure du projet

```
ProjectAnsible/
├── site.yml                        # Playbook principal
├── playbook-proxy.yml              # Reverse proxy sur le Master
├── Inventories/
│   ├── inventaires.ini             # Définition des hosts
│   └── group_vars/
│       ├── all.yml                 # Variables globales
│       └── vault.yml               # Secrets chiffrés (Ansible Vault)
└── roles/
    ├── common/
    │   └── tasks/main.yml          # Mise à jour, paquets de base, fuseau horaire
    ├── firewall/
    │   └── tasks/main.yml          # Configuration UFW (ports 22, 80, 443, 3306)
    ├── nginx/
    │   ├── tasks/main.yml          # Installation et configuration Nginx
    │   ├── handlers/main.yml       # Handler reload Nginx
    │   └── templates/nginx.conf.j2 # Template de configuration virtual host
    ├── php/
    │   └── tasks/main.yml          # PHP 8.1-FPM et extensions
    ├── mysql/
    │   ├── tasks/main.yml          # MySQL, base de données, utilisateur
    │   └── handlers/main.yml       # Handler restart MySQL
    └── app/
        ├── tasks/main.yml          # Déploiement application PHP
        └── templates/
            ├── index.php.j2        # Page web principale
            └── env.j2              # Fichier de configuration .env
```

---

## Installation

### 1. Cloner le projet sur le Master

```bash
ssh -i ~/.ssh/williamkey admin12@15.222.196.252
git clone <url-du-repo> ~/ProjectAnsible
cd ~/ProjectAnsible
```

### 2. Créer le fichier Vault

```bash
ansible-vault create Inventories/group_vars/vault.yml
```

Contenu à saisir (remplace les valeurs) :

```yaml
vault_mysql_root_password: "TonMotDePasseRoot"
vault_mysql_db_password: "TonMotDePasseApp"
```

### 3. Tester la connectivité

```bash
ansible -i Inventories/inventaires.ini all -m ping
```

Résultat attendu :

```
node1 | SUCCESS => { "ping": "pong" }
node2 | SUCCESS => { "ping": "pong" }
node3 | SUCCESS => { "ping": "pong" }
node4 | SUCCESS => { "ping": "pong" }
```

### 4. Vérifier la syntaxe

```bash
ansible-playbook -i Inventories/inventaires.ini site.yml --ask-vault-pass --syntax-check
```

### 5. Lancer le déploiement

```bash
ansible-playbook -i Inventories/inventaires.ini site.yml --ask-vault-pass
```

### 6. Installer le reverse proxy sur le Master

```bash
ansible-playbook playbook-proxy.yml
```

L'application est ensuite accessible sur **http://15.222.196.252**

---

## Variables de configuration

Fichier `Inventories/group_vars/all.yml` :

| Variable | Valeur par défaut | Description |
|---|---|---|
| `nginx_port` | `80` | Port d'écoute Nginx |
| `server_name` | `mon-app.local` | Nom du virtual host |
| `web_root` | `/var/www/html/app` | Racine du site web |
| `php_version` | `8.1` | Version PHP à installer |
| `mysql_db_name` | `app_db` | Nom de la base de données |
| `mysql_db_user` | `app_user` | Utilisateur MySQL applicatif |
| `mysql_db_host` | `10.0.2.13` | IP du serveur MySQL |

---

## Commandes utiles

```bash
# Déployer seulement un rôle spécifique
ansible-playbook -i Inventories/inventaires.ini site.yml --tags nginx --ask-vault-pass

# Simuler le déploiement sans rien modifier (dry-run)
ansible-playbook -i Inventories/inventaires.ini site.yml --ask-vault-pass --check

# Vérifier les services sur les nodes web
ansible webservers -i Inventories/inventaires.ini -m shell -a "systemctl status nginx php8.1-fpm" --become --ask-vault-pass

# Vérifier MySQL sur node3
ansible dbservers -i Inventories/inventaires.ini -m shell -a "systemctl status mysql" --become --ask-vault-pass

# Vérifier les règles UFW
ansible all -i Inventories/inventaires.ini -m shell -a "ufw status" --become --ask-vault-pass

# Editer les secrets Vault
ansible-vault edit Inventories/group_vars/vault.yml
```

---

## Sécurité

- **Ansible Vault** — tous les mots de passe sont chiffrés en AES-256 dans `vault.yml`
- **UFW** — pare-feu actif sur tous les nodes, politique par défaut `deny`
- **MySQL** — port 3306 ouvert uniquement depuis le subnet interne `10.0.2.0/24`
- **SSH** — authentification par clé uniquement (`williamkey`), accès root désactivé
- **Subnet privé** — Node1 à Node4 sans IP publique, injoignables depuis Internet

---

## Idempotence

Un des principes fondamentaux d'Ansible est l'idempotence : relancer le playbook sur une infrastructure déjà configurée ne produit aucun changement.

```bash
# Résultat du 2e lancement :
node1 : ok=17  changed=0  failed=0
node2 : ok=17  changed=0  failed=0
node3 : ok=14  changed=0  failed=0
node4 : ok=9   changed=0  failed=0
```

---

## Résultat final

L'application web déployée est accessible sur **http://15.222.196.252** et affiche :

- Le nom du serveur web actif (node1 ou node2)
- La version PHP installée
- Le statut de connexion à la base de données MySQL
- La date et heure en temps réel

---

## Auteur

**William Nelson** — Projet d'automatisation infrastructure, avril 2026
