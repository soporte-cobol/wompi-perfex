# 💳 Wompi Payment Gateway para Perfex CRM

[![Versión](https://img.shields.io/badge/Versión-1.1.0-blue)](https://github.com)
[![Perfex CRM](https://img.shields.io/badge/Perfex%20CRM-2.3.2%2B-green)](https://www.perfexcrm.com)
[![Wompi](https://img.shields.io/badge/Powered%20by-Wompi%20Bancolombia-orange)](https://wompi.com)
[![Trial](https://img.shields.io/badge/Trial%20Gratis-30%20días-brightgreen)](https://control.cobol.com.co)

> Integra **Wompi by Bancolombia** como pasarela de pago nativa en tu Perfex CRM.  
> Tus clientes podrán pagar facturas con **tarjeta crédito/débito, PSE, Nequi, Daviplata y más**.

---

## ✨ Una Experiencia de Pago Premium

Este módulo ha sido rediseñado para ofrecer la mejor experiencia de usuario en Perfex CRM, combinando estética moderna con funcionalidad automatizada.

### 💎 Características de la Interfaz
| Característica | Beneficio para el Cliente |
|---|---|
| **🎨 Glassmorphism Design** | Interfaz moderna, traslúcida y elegante que transmite profesionalismo. |
| **⚡ Auto-Trigger** | El checkout de Wompi se abre automáticamente, reduciendo la fricción. |
| **🎉 Confetti Celebration** | Animación festiva en pagos exitosos que mejora la percepción de marca. |
| **📱 100% Responsive** | Optimizado para que tus clientes paguen desde cualquier celular o tablet. |
| **⏳ Carga Inteligente** | Resumen de factura visible mientras se conecta con la pasarela. |

---

## 🚀 Instalación rápida (5 minutos)

### Paso 1 — Descargar el módulo

Descarga la carpeta `wompi` de este repositorio.  
Deberías tener esta estructura:

```
wompi/
├── controllers/
│   └── Callback.php
├── language/
│   └── spanish/
│       └── wompi_lang.php
├── libraries/
│   ├── Wompi_gateway.php
│   └── Wompi_license.php
├── views/
│   └── payment_result.php
└── wompi.php
```

### Paso 2 — Subir al servidor

Copia la carpeta `wompi` dentro de la carpeta `modules` de tu Perfex CRM:

```
tu-servidor.com/
└── perfex_crm/          ← Tu instalación de Perfex
    └── modules/
        └── wompi/        ← Pega aquí la carpeta completa
```

> 💡 Puedes subir los archivos con **FileZilla**, el **administrador de archivos de cPanel**,  
> o via **SFTP** si tienes acceso SSH.

### Paso 3 — Activar el módulo en Perfex CRM

1. Entra al panel de administración de tu Perfex CRM
2. Ve al menú: **Configuración → Módulos**
3. Busca **"Wompi Payment Gateway"** en la lista
4. Haz clic en **Activar** 🟢

> 🎉 **¡El módulo se activa y automáticamente obtiene tu licencia de prueba de 30 días!**  
> No necesitas hacer nada más por ahora.

### Paso 4 — Ingresar tus credenciales de Wompi

1. Ve a: **Configuración → Pago → Pasarelas de Pago → Wompi**
2. Completa los siguientes campos:

| Campo | Qué ingresar |
|---|---|
| **Active** | Sí |
| **Currencies** | `COP` |
| **Llave Pública** | Tu llave pública de Wompi (empieza con `pub_stagtest_` en pruebas) |
| **Llave Privada** | Tu llave privada de Wompi (empieza con `prv_stagtest_` en pruebas) |
| **Secreto de Integridad** | Tu secreto de integridad de Wompi |
| **Secreto de Eventos** | Tu secreto de eventos de Wompi (para webhooks) |
| **Modo Sandbox** | **Sí** para pruebas / **No** para producción real |
| **Permitir pagos parciales** | **No** (recomendado) para que el cliente no cambie el monto |

3. Haz clic en **Guardar cambios**

---

## 🔑 Cómo obtener las credenciales de Wompi

1. Ve a [https://comercios.wompi.co](https://comercios.wompi.co) e inicia sesión
2. En el menú lateral, haz clic en **"Desarrolladores"**
3. Encontrarás 2 conjuntos de llaves:

   **🟡 Ambiente de Pruebas (Sandbox):**
   - Llave pública: empieza con `pub_stagtest_...`
   - Llave privada: empieza con `prv_stagtest_...`
   - Secreto de integridad: una cadena larga
   - Secreto de eventos: otra cadena larga

   **🟢 Ambiente de Producción (Dinero real):**
   - Llave pública: empieza con `pub_prod_...`
   - Llave privada: empieza con `prv_prod_...`
   - Secreto de integridad: una cadena larga
   - Secreto de eventos: otra cadena larga

> ⚠️ **Usa siempre las llaves de Sandbox primero** para probar que todo funciona  
> antes de activar las llaves de Producción.

---

## 🔑 Licencia — Cómo funciona

### ✅ Trial automático de 30 días — GRATIS

**Cuando activas el módulo por primera vez:**
- El módulo detecta que no tienes licencia
- Se conecta automáticamente a `control.cobol.com.co`
- En segundos, tu servidor recibe una **licencia de prueba gratuita de 30 días**
- La licencia se guarda automáticamente — **no necesitas hacer nada**

**¿Qué incluye el trial?**
- Acceso completo a todas las funciones del módulo
- Sin restricciones, sin marca de agua
- 30 días calendario desde el momento de activación

**¿Qué pasa al vencer el trial?**
- El módulo mostrará un aviso en tu panel de administración
- El botón de Wompi dejará de aparecer para tus clientes
- Tus datos y configuración NO se borran
- Solo necesitas activar un plan para que todo vuelva a funcionar

---

### 💰 Planes y precios

| Plan | Precio | Descripción |
|---|---|---|
| 🟡 **Trial** | **GRATIS** | 30 días completos, sin tarjeta de crédito |
| 🔵 **Mensual** | **$12 USD/mes** | Facturación mensual, cancela cuando quieras |
| 🟢 **Anual** | **$120 USD/año** | Equivale a 10 meses — **ahorras 2 meses** |
| ⭐ **Vitalicio** | **$249 USD** | Pago único, acceso de por vida — **el más popular** |

> 💡 **Recomendación:** El plan **Vitalicio** es la mejor inversión. En 2 años ya te ahorras dinero  
> comparado con el plan mensual, y nunca más te preocupas por renovar.

---

### 🛒 Cómo comprar una licencia

1. Ve a: **[https://control.cobol.com.co/index.php?rp=/store/contenidos/wompi-perfex](https://control.cobol.com.co/index.php?rp=/store/contenidos/wompi-perfex)**
2. Selecciona el plan que prefieres (Mensual, Anual o Vitalicio)
3. Completa el registro con tu nombre, email y dominio de tu Perfex CRM
4. Realiza el pago
5. Recibirás tu **Llave de Licencia** por correo electrónico

### 🔧 Cómo activar tu licencia comprada

1. Entra al panel de administración de tu Perfex CRM
2. Ve a: **Configuración → Pago → Pasarelas de Pago → Wompi**
3. Busca el campo **"Llave de Licencia"**
4. Pega la llave que recibiste por email
5. Haz clic en **Guardar cambios**
6. ¡Listo! Tu módulo queda activado permanentemente

---

## 🌐 Configurar el Webhook (recomendado)

El webhook permite que Wompi notifique a tu servidor cuando un pago se procesa,  
incluso si el cliente cierra el navegador antes de ser redirigido.

1. Inicia sesión en [comercios.wompi.co](https://comercios.wompi.co)
2. Ve a **Desarrolladores → Eventos**
3. Agrega la siguiente URL como endpoint de webhook:

```
https://TU-DOMINIO.COM/wompi/callback/webhook
```

> Reemplaza `TU-DOMINIO.COM` con el dominio real de tu Perfex CRM

4. Activa el evento: `transaction.updated`
5. Guarda los cambios

---

## 🧪 Tarjetas de prueba (Sandbox)

Usa estas tarjetas para probar sin cobrar dinero real:

| Tarjeta | Número | Resultado |
|---|---|---|
| ✅ Visa Aprobada | `4242 4242 4242 4242` | Pago aprobado |
| ❌ Visa Declinada | `4111 1111 1111 1111` | Pago declinado |
| ⏳ Nequi Pendiente | Cualquier teléfono | Queda pendiente |

- **Fecha de vencimiento:** Cualquier fecha futura (ej: `12/28`)
- **CVV:** Cualquier número de 3 dígitos (ej: `123`)
- **Cuotas:** 1

---

## ❓ Preguntas frecuentes

**¿Necesito cuenta en Wompi para usar este módulo?**  
Sí. Regístrate gratis en [wompi.com](https://wompi.com). El proceso toma unos minutos.

**¿El módulo funciona con cualquier versión de Perfex CRM?**  
Funciona con Perfex CRM 2.3.2 o superior. Si tienes una versión anterior, actualiza primero.

**¿Qué pasa si mi servidor no tiene internet saliente?**  
El módulo necesita conectarse a `control.cobol.com.co` para validar la licencia una vez cada 24 horas. Si tu servidor no puede hacer conexiones salientes, contacta al soporte.

**¿Puedo usar el módulo en más de un dominio?**  
Cada licencia es válida para **un dominio**. Si tienes varios sitios de Perfex CRM, necesitas una licencia por cada uno.

**¿Qué métodos de pago acepta Wompi?**  
Tarjeta crédito y débito (Visa, Mastercard, Amex), PSE (todas las entidades bancarias colombianas), Nequi, Daviplata y Bancolombia a la mano.

**¿Puedo cancelar el plan mensual cuando quiera?**  
Sí, cancelas desde tu panel en `control.cobol.com.co` y el módulo seguirá activo hasta el fin del período pagado.

**¿El plan vitalicio incluye actualizaciones futuras?**  
Sí. El plan vitalicio incluye todas las actualizaciones del módulo sin costo adicional.

**Mi trial venció y quiero comprar, ¿pierdo la configuración?**  
No. Tu configuración (credenciales de Wompi, ajustes, etc.) se mantiene intacta. Solo debes activar un plan para que el módulo vuelva a funcionar.

---

## 🆘 Soporte

| Canal | Contacto |
|---|---|
| 🌐 Portal de soporte | [control.cobol.com.co/support](https://control.cobol.com.co/support) |
| 📧 Email | soporte@cobol.com.co |
| 🐛 Bugs / Issues | [GitHub Issues](../../issues) |
| 💬 Preguntas generales | [GitHub Discussions](../../discussions) |

> Tiempo de respuesta: **menos de 24 horas en días hábiles**

---

## 🔐 Seguridad

- Las llaves privadas y secretos se almacenan **encriptadas** en tu base de datos
- Los webhooks se verifican con `hash_equals()` para prevenir ataques de temporización
- La licencia se valida contra nuestros servidores cada **24 horas** (sin bloquear la carga de página)
- El módulo **no almacena** datos de tarjetas — todo el proceso de pago ocurre en los servidores de Wompi
- **Validación Resiliente:** Sistema de licencias normalizado para máxima compatibilidad con diferentes hostings y dominios.

---

## 📋 Requisitos del sistema

| Requisito | Mínimo |
|---|---|
| Perfex CRM | 2.3.2 o superior |
| PHP | 7.4 o superior |
| Extensión cURL | Habilitada |
| HTTPS | Requerido en producción |
| Conexión a internet | Requerida (para validación de licencia y webhook) |

---

## 📄 Licencia del código

Este repositorio contiene la versión **Community** del módulo.  
El código fuente se distribuye bajo licencia **MIT** para uso personal y comercial,  
pero requiere una **licencia activa** de [control.cobol.com.co](https://control.cobol.com.co) para funcionar.

---

<div align="center">

**¿Te gustó el módulo?** ⭐ Dale una estrella en GitHub y ayuda a otros a encontrarlo.

[🛒 Comprar licencia](https://control.cobol.com.co/index.php?rp=/store/contenidos/wompi-perfex) · [📖 Documentación](../../wiki) · [🐛 Reportar bug](../../issues) · [💬 Comunidad](../../discussions)

</div>
