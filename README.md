# FACTUSYS - Sistema de Facturación Electrónica SUNAT UBL 2.1 & CRM

Sistema web empresarial completo de Facturación Electrónica SUNAT (Facturas, Boletas, Proformas, Guías de Remisión, Exportaciones aduaneras e Incoterms) con CRM Kanban integrado, soporte PWA y modo claro/oscuro adaptativo.

## 🚀 Características Principales

- **Facturación Electrónica SUNAT**: Emisión en vivo y homologación UBL 2.1 vía OSE / Nubefact y Greenter.
- **Módulo de Exportación**: Despachos aduaneros, Incoterms, partidas arancelarias y DAM.
- **CRM Kanban & Ventas**: Gestión de embudo comercial y seguimiento de oportunidades.
- **Panel Administrativo & Dashboard**: Estadísticas y comportamiento de ventas en tiempo real (7 días, 30 días y mes en curso).
- **Personalización de Marca & PWA**: Configuración de logotipos modo claro/oscuro, paletas de colores y soporte offline.
- **Actualización Segura**: Mecanismo de actualización desde el repositorio GitHub preservando bases de datos, credenciales y archivos sensibles sin sobreescritura.
- **Migraciones Automáticas de Base de Datos**: Ejecución de cambios estructurales automáticos en la base de datos al actualizar.

## ⚙️ Requisitos

- PHP 8.1 o superior con extensiones `pdo_mysql`, `curl`, `openssl`, `mbstring`, `zip`, `xml`.
- MySQL 5.7+ o MariaDB 10.4+.
- Apache / Nginx con módulo `mod_rewrite` habilitado (ej. XAMPP, Laragon o servidor Linux).

## 🛠️ Instalación Rápida

1. Clona el repositorio en tu servidor o carpeta `htdocs`:
   ```bash
   git clone https://github.com/networkturbo4-pixel/facturaci-n.git
   ```
2. Asegúrate de que los directorios `storage/` y `uploads/` tengan permisos de escritura.
3. El sistema detecta y crea automáticamente la base de datos `facturacion_crm` y ejecuta todas las migraciones al ingresar por primera vez.
4. Credenciales por defecto:
   - **Email**: `admin@empresa.com`
   - **Contraseña**: `admin123`

## 🔒 Protección de Configuración y Datos Locales

El sistema utiliza `includes/db.config.php` (ignorado en Git) para almacenar las credenciales de base de datos locales. Cada vez que el sistema se actualiza desde el repositorio, tu base de datos existente, tus archivos adjuntos y tu configuración local se mantienen 100% intactos.
