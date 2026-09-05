<div align="center">

# ⚡ WabaMini

### Enterprise-Grade AI Omnichannel WhatsApp Automation & Multi-Tenant SaaS Platform

[![Laravel 11](https://img.shields.io/badge/Laravel-11.x-FF2D20?style=for-the-badge&logo=laravel&logoColor=white)](https://laravel.com)
[![Next.js 15](https://img.shields.io/badge/Next.js-15.x-000000?style=for-the-badge&logo=nextdotjs&logoColor=white)](https://nextjs.org)
[![TypeScript](https://img.shields.io/badge/TypeScript-5.x-3178C6?style=for-the-badge&logo=typescript&logoColor=white)](https://www.typescriptlang.org)
[![Tailwind CSS](https://img.shields.io/badge/Tailwind-3.x-38B2AC?style=for-the-badge&logo=tailwind-css&logoColor=white)](https://tailwindcss.com)
[![MySQL](https://img.shields.io/badge/MySQL-8.0-4479A1?style=for-the-badge&logo=mysql&logoColor=white)](https://mysql.com)
[![Redis](https://img.shields.io/badge/Redis-7.x-DC382D?style=for-the-badge&logo=redis&logoColor=white)](https://redis.io)
[![Docker](https://img.shields.io/badge/Docker-Ready-2496ED?style=for-the-badge&logo=docker&logoColor=white)](https://www.docker.com)
[![License: MIT](https://img.shields.io/badge/License-MIT-yellow.svg?style=for-the-badge)](LICENSE)

<p align="center">
  <b>Scale customer conversations, automated visual workflows, and autonomous AI agents across WhatsApp, Telegram, Instagram, Messenger, and Webchat.</b>
</p>

[Quick Start](#-quick-start-local-development) • [Architecture](#-architecture) • [Key Features](#-key-features) • [VPS Deployment Guide](#-production-vps-deployment) • [API Documentation](#-api-endpoints)

</div>

---

## 🚀 Overview

**WabaMini** is a full-stack, enterprise-ready Omnichannel CRM & Marketing Automation platform engineered for businesses, agencies, and SaaS providers. It unifies official Meta WhatsApp Cloud API with self-hosted Baileys WhatsApp multi-device workers, drag-and-drop conversational automation flows, RAG-powered AI chatbots, and multi-tenant subscription billing.

```
                                  ┌────────────────────────┐
                                  │      Client Web UI     │
                                  │  (Next.js 15 App / TS) │
                                  └───────────┬────────────┘
                                              │ (HTTP / WS Proxy)
                                  ┌───────────▼────────────┐
                                  │      Nginx Gateway     │
                                  └─────┬────────────┬─────┘
                                        │            │
                  ┌─────────────────────▼─┐        ┌─▼─────────────────────┐
                  │    Laravel 11 REST    │        │  Laravel Reverb (WS)  │
                  │   API & Controller    │        │   Real-time Broadcast │
                  └───────────┬───────────┘        └───────────────────────┘
                              │
     ┌────────────────────────┼────────────────────────┐
     │                        │                        │
┌────▼─────────────┐   ┌──────▼───────────┐   ┌────────▼───────────┐
│  MySQL 8 (Data)  │   │  Redis 7 (Queue) │   │  Baileys Worker    │
│  Multi-Tenant DB │   │  Cache & Horizon │   │  (Node.js Session) │
└──────────────────┘   └──────────────────┘   └────────────────────┘
```

---

## ✨ Key Features

### 💬 Omnichannel Messaging & WhatsApp Engine
- **Dual WhatsApp Engine**: Native support for **WhatsApp Cloud API** (official business templates) and **Baileys Worker** (QR-code pairing, zero Meta per-message fee).
- **Multi-Channel Inbox**: WhatsApp, Telegram, Instagram DM, Facebook Messenger, SMS, and Email.
- **Shared Team Inbox**: Real-time agent chat assignment, canned responses, internal collision detection, and private notes.

### 🤖 Autonomous AI Agents & Knowledge Bases
- **Multi-LLM Integration**: Plug-and-play with OpenAI (GPT-4o), Anthropic (Claude 3.5), Google (Gemini 1.5), Groq, Mistral, and self-hosted Ollama.
- **RAG Knowledge Base**: Upload PDFs, DOCX, text files, or website URLs for intelligent vector-backed customer answering.
- **Human Handoff & Sentiment Safeguards**: Automatically route complex queries to live team members.

### ⚡ Visual Flow Automation Builder
- **Node-Based Canvas**: Drag-and-drop automation triggers, conditions, delays, webhooks, WhatsApp interactive buttons, and list menus.
- **Flowblueprints Marketplace**: Pre-built templates for lead qualification, appointment booking, e-commerce order tracking, and feedback collection.

### 🛍️ E-Commerce & Abandoned Cart Recovery
- **Native Store Connectors**: One-click integration with Shopify and WooCommerce.
- **Cart Abandonment Workflows**: Automated WhatsApp nudge sequences with dynamic checkout links and discount vouchers.
- **Catalog & Order Sync**: Synchronize product catalogs and push real-time shipping/delivery status updates.

### 💳 Multi-Tenant SaaS & Subscription Billing
- **Multiple Payment Gateways**: Stripe, Razorpay, Paystack, and Flutterwave.
- **Granular Plan Limits**: Whitelist integrations per plan, allocate AI token quotas, channel connection limits, and flow credit limits.
- **Full Super Admin Portal**: Manage tenants, track platform revenue, audit user sessions, and broadcast system notifications.

---

## 🛠️ Tech Stack

| Layer | Technology |
| :--- | :--- |
| **Frontend** | Next.js 15, React 19, TypeScript, Tailwind CSS, Lucide Icons, Shadcn UI |
| **Backend** | Laravel 11.x, PHP 8.3 / 8.4, Laravel Sanctum, Laravel Horizon, Laravel Scout |
| **Realtime WebSockets** | Laravel Reverb, Pusher-compatible WebSocket client |
| **Database** | MySQL 8.0 with InnoDB, full relational schema (60+ tables) |
| **Caching & Queues** | Redis 7.x (Alpine), Laravel Queue Worker |
| **WhatsApp Worker** | Node.js 20, @whiskeysockets/baileys, Express, WebSocket |
| **Proxy & Ingress** | Nginx Alpine Gateway, Let's Encrypt / Certbot SSL |

---

## ⚡ Quick Start (Local Development)

### Prerequisites
- **Git**
- **Docker & Docker Compose** (Recommended) or local **PHP 8.3+**, **Node.js 20+**, and **MySQL 8.0**

### 1. Clone the Repository
```bash
git clone https://github.com/abhishekaddepalli/wabamini.git
cd wabamini
```

### 2. Configure Environment Files
```bash
# Backend Environment
cp backend/.env.example backend/.env

# Frontend Environment
cp frontend/.env.example frontend/.env.local

# Baileys Worker Environment
cp baileys-worker/.env.example baileys-worker/.env
```

### 3. Launch via Docker (One Command)
```bash
docker compose up -d
```
All services will initialize automatically:
- **Frontend App**: `http://localhost:3000`
- **Super Admin Login**: `http://localhost:3000/superadmin/login`
- **Backend API**: `http://localhost:8001` (or `http://localhost:8088` via Nginx)
- **Reverb WebSockets**: `http://localhost:8080`
- **WhatsApp Worker**: `http://localhost:5001`

### Default Super Admin Credentials
```yaml
URL:      http://localhost:3000/superadmin/login
Email:    admin@whatsomni.com
Password: Password123!
```

---

## 🌐 Production VPS Deployment

Deploying **WabaMini** to any Cloud VPS (Ubuntu 22.04 or 24.04 on DigitalOcean, Hetzner, AWS EC2, Linode, Contabo) is straightforward with our production Docker stack.

👉 **[Read the Full Step-by-Step VPS Production Guide (DEPLOYMENT.md)](DEPLOYMENT.md)**

### Quick VPS Summary
1. **Provision VPS**: Ubuntu 22.04 LTS (minimum 2 vCPU, 4GB RAM + 4GB Swap).
2. **DNS Setup**: Point `app.yourdomain.com` and `api.yourdomain.com` to your VPS IP.
3. **Install Docker**: `curl -fsSL https://get.docker.com | sh`
4. **Deploy Stack**:
   ```bash
   git clone https://github.com/abhishekaddepalli/wabamini.git /var/www/wabamini
   cd /var/www/wabamini
   cp .env.example .env.production
   # Update domain and secure credentials in .env.production
   docker compose -f docker-compose.prod.yml up -d --build
   ```
5. **Issue SSL Certificate**:
   ```bash
   sudo certbot certonly --webroot -w /var/www/certbot -d app.yourdomain.com -d api.yourdomain.com
   ```

---

## 🔒 Security & Best Practices

- **Sanctum Stateful Authentication**: HttpOnly, SameSite cookies with CSRF token validation.
- **Tenant Scope Isolation**: Strict multi-tenant query scoping to prevent data cross-talk.
- **Encrypted Tokens**: Access tokens, WhatsApp session keys, and CRM credentials are encrypted at rest using AES-256-CBC.
- **Rate Limiting**: Built-in API throttling on authentication and messaging endpoints.

---

## 📄 License

Distributed under the **MIT License**. See `LICENSE` for more information.

---

<div align="center">
  <b>WabaMini</b> • Built with ❤️ for scalable business communication.
</div>
