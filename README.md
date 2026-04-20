# owl.li

Self-hosted anonymous email service. Privacy-first, no JavaScript, no logs.

## Features

- **NO JS** — 100% server-rendered HTML+CSS, works in any browser
- **NO LOGS** — No IP, User-Agent, or referrer stored anywhere
- **Multi-domain** — Support multiple email domains from single install
- **Tor support** — Separate .onion addresses for web and mail
- **Privacy headers** — All identifying headers stripped from outgoing mail
- **PoW spam protection** — Proof-of-work SMTP unblock (no JS required)
- **Warrant canary** — PGP-signed transparency reports

## Tech Stack

- **Backend:** PHP 8.3, Slim 4, Twig
- **Database:** PostgreSQL 16, Redis
- **Mail:** Postfix, Dovecot, Rspamd
- **Web:** nginx (no access logs)
- **Infra:** Docker Compose, Tor v3

## Quick Start

```bash
# Clone and configure
git clone <repo> mailservice
cd mailservice
cp .env.example .env
# Edit .env with your values

# Full installation (≤30 min on clean Ubuntu 24.04)
make all

# Or step by step:
make init      # Generate keys, init database
make up        # Start all services
make tls       # Generate TLS certificates
make tor       # Start Tor hidden services
make admin     # Create admin user
```

## Documentation

- [Installation Guide](docs/INSTALL.md)
- [Architecture](docs/ARCHITECTURE.md)
- [Security](docs/SECURITY.md)
- [API Reference](docs/API.md)

## Directory Structure

```
mailservice/
├── app/                    # PHP application
│   ├── public/             # Web document root
│   ├── src/                # Application source
│   ├── templates/          # Twig templates
│   └── migrations/         # Database migrations
├── webmail/                # Webmail document root
├── admin/                  # Admin panel document root
├── config/                 # Service configurations
│   ├── nginx/
│   ├── postfix/
│   ├── dovecot/
│   ├── rspamd/
│   ├── tor/
│   └── php-fpm/
├── scripts/                # Utility scripts
├── tests/                  # Test suites
└── docs/                   # Documentation
```

## Makefile Targets

| Target | Description |
|--------|-------------|
| `make all` | Full installation from scratch |
| `make up` | Start all containers |
| `make down` | Stop all containers |
| `make init` | First-time setup (keys, database) |
| `make init-db` | Run database migrations |
| `make tls` | Generate/renew TLS certificates |
| `make tor` | Start Tor hidden services |
| `make admin` | Create admin user |
| `make healthcheck` | Run health checks |
| `make test` | Run all tests |
| `make backup` | Backup data |
| `make clean` | Remove containers and volumes |
| `make help` | Show all targets |

## Configuration

All configuration via `.env` file. See `.env.example` for all options.

Key settings:
- `PRIMARY_DOMAIN` — Main domain name
- `DB_PASSWORD` — PostgreSQL password
- `POW_DIFFICULTY_BITS` — PoW difficulty (22 ≈ 1-3 min)
- `TOR_ENABLED` — Enable Tor hidden services
- `ADMIN_IP_ALLOWLIST` — IPs allowed to access admin

## Security

See [SECURITY.md](docs/SECURITY.md) for:
- TLS configuration
- Header security
- Rate limiting
- Privacy hardening
- Audit checklist

## License

MIT
