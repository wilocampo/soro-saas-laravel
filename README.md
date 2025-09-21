# 🚀 Laravel + Vue.js + PrimeVue SaaS Application

A complete multi-tenant SaaS application built with Laravel 11, Vue 3, and PrimeVue, featuring user management, role-based access control, and subdomain-based multitenancy.

## ✨ Features

### 🔐 Authentication & User Management
- **Dynamic User Dropdown** - Real user data with avatar support and default initials
- **User Management** - Complete CRUD operations with Sakai-style UI
- **Role-Based Access Control** - Using Spatie Laravel Permission
- **Profile Management** - User profile editing and password updates

### 🔔 Notification System
- **Notification Dropdown** - Real-time notifications with mark as read functionality
- **Notification Page** - Full notification management interface
- **Hover Effects** - Optimized for both light and dark modes

### 🏢 Multitenancy
- **Subdomain-Based Tenants** - Each tenant gets their own subdomain
- **Database Isolation** - Separate database per tenant using Spatie Multitenancy
- **Tenant Management** - Create, manage, and configure tenants
- **Context-Aware Routing** - Landlord vs tenant-specific routes

### 🎨 Modern UI/UX
- **PrimeVue Components** - Professional UI component library
- **Tailwind CSS** - Utility-first CSS framework
- **Dark/Light Mode** - Complete theme support
- **Responsive Design** - Mobile-first approach
- **Sakai Theme** - Based on PrimeVue's Sakai demo

### 🧭 Navigation & Layout
- **Breadcrumb Navigation** - Dynamic breadcrumbs with home icon
- **Sidebar Menu** - Collapsible navigation with icons
- **Top Bar** - User dropdown, notifications, and theme toggle
- **Floating Configurator** - Theme customization (login page excluded)

## 🏗️ Architecture

### Backend (Laravel 11)
- **Laravel 11** - Latest Laravel framework
- **Inertia.js** - SPA experience without API complexity
- **Spatie Multitenancy** - Multi-tenant architecture
- **Spatie Laravel Permission** - Role and permission management
- **SQLite Database** - Development database with migrations

### Frontend (Vue 3)
- **Vue 3 Composition API** - Modern reactive framework
- **PrimeVue 4.3.9** - Professional UI components
- **Tailwind CSS** - Utility-first styling
- **PrimeIcons** - Comprehensive icon library

## 📁 Project Structure

```
├── app/
│   ├── Http/Controllers/          # API Controllers
│   │   ├── TenantController.php   # Tenant management
│   │   ├── UserController.php     # User management
│   │   └── SettingsController.php # System settings
│   ├── Http/Middleware/           # Custom middleware
│   │   ├── LandlordMiddleware.php # Landlord access control
│   │   └── TenantMiddleware.php   # Tenant access control
│   ├── Models/
│   │   ├── Tenant.php             # Tenant model with IsTenant interface
│   │   └── User.php               # User model with tenant relationship
│   └── Multitenancy/              # Multitenancy implementation
│       ├── SubdomainTenantFinder.php
│       └── SwitchTenantDatabaseTask.php
├── resources/js/
│   ├── components/                # Reusable Vue components
│   │   ├── AppBreadcrumb.vue      # Breadcrumb navigation
│   │   ├── UserDropdown.vue       # User dropdown menu
│   │   └── NotificationDropdown.vue # Notification system
│   ├── Pages/                     # Vue pages
│   │   ├── Users/                 # User management pages
│   │   ├── Tenants/               # Tenant management pages
│   │   └── Settings/              # System settings
│   └── layout/                    # Layout components
│       ├── AppLayout.vue          # Main layout
│       ├── AppMenu.vue            # Sidebar menu
│       └── AppTopbar.vue          # Top navigation
└── routes/
    └── web.php                    # Application routes
```

## 🚀 Installation

### Prerequisites
- PHP 8.2+
- Composer
- Node.js 18+
- npm or yarn

### Setup

1. **Clone the repository**
   ```bash
   git clone <repository-url>
   cd <project-directory>
   ```

2. **Install PHP dependencies**
   ```bash
   composer install
   ```

3. **Install Node.js dependencies**
   ```bash
   npm install
   ```

4. **Environment setup**
   ```bash
   cp .env.example .env
   php artisan key:generate
   ```

5. **Database setup**
   ```bash
   php artisan migrate
   php artisan db:seed
   ```

6. **Build assets**
   ```bash
   npm run build
   ```

7. **Start development server**
   ```bash
   php artisan serve
   npm run dev
   ```

## 🔧 Configuration

### Multitenancy Setup
The application uses subdomain-based multitenancy. Configure your local environment:

1. **Add to hosts file** (`/etc/hosts` on Linux/Mac, `C:\Windows\System32\drivers\etc\hosts` on Windows):
   ```
   127.0.0.1 soro.local
   127.0.0.1 tenant1.soro.local
   127.0.0.1 tenant2.soro.local
   ```

2. **Configure web server** to point subdomains to the application

### Database Configuration
- **Main Database**: `database/database.sqlite` (landlord data)
- **Tenant Databases**: Created dynamically per tenant
- **Migrations**: Run `php artisan migrate` for main database

## 🎯 Usage

### Accessing the Application
- **Main Domain**: `http://soro.local` (Landlord access)
- **Tenant Subdomains**: `http://tenant1.soro.local` (Tenant access)

### User Roles
- **Super Admin**: Full system access
- **Admin**: Tenant management access
- **Manager**: User management within tenant
- **User**: Basic user access

### Key Features Usage
1. **User Management**: Navigate to `/users` for CRUD operations
2. **Tenant Management**: Navigate to `/tenants` for tenant administration
3. **Notifications**: Click the bell icon in the top bar
4. **Settings**: Navigate to `/settings` for system configuration

## 🛠️ Development

### Available Commands
```bash
# Laravel commands
php artisan serve              # Start Laravel server
php artisan migrate           # Run migrations
php artisan db:seed           # Seed database
php artisan tinker            # Interactive shell

# Frontend commands
npm run dev                   # Start Vite dev server
npm run build                 # Build for production
npm run watch                 # Watch for changes
```

### Code Style
- **PHP**: Follows PSR-12 standards
- **Vue**: Composition API with TypeScript-like patterns
- **CSS**: Tailwind CSS utility classes

## 📦 Dependencies

### Backend
- Laravel 11
- Spatie Multitenancy
- Spatie Laravel Permission
- Inertia.js

### Frontend
- Vue 3
- PrimeVue 4.3.9
- Tailwind CSS
- PrimeIcons

## 🤝 Contributing

1. Fork the repository
2. Create a feature branch (`git checkout -b feature/amazing-feature`)
3. Commit your changes (`git commit -m 'Add some amazing feature'`)
4. Push to the branch (`git push origin feature/amazing-feature`)
5. Open a Pull Request

## 📄 License

This project is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## 🙏 Acknowledgments

- [Laravel](https://laravel.com/) - The PHP framework
- [Vue.js](https://vuejs.org/) - The progressive JavaScript framework
- [PrimeVue](https://primevue.org/) - The Vue UI component library
- [Spatie](https://spatie.be/) - Laravel packages for multitenancy and permissions
- [Tailwind CSS](https://tailwindcss.com/) - Utility-first CSS framework

---

**Built with ❤️ using Laravel, Vue.js, and PrimeVue**