=== Vavi WooCommerce ===
Contributors: mrpac6689
Tags: woocommerce, vavi, printbee, frete, shipping, estoque de arte
Requires at least: 6.0
Tested up to: 7.1
Requires PHP: 7.4
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Integra sua loja WooCommerce com a API Vavi Estampas/PrintBee: frete real por CEP, rascunho de pedido automático e webhooks de status/rastreio.

== Description ==

Plugin genérico que conecta qualquer loja WooCommerce à API da Vavi Estampas / PrintBee (https://api.printbee.com.br):

* **Frete real por CEP** — cotações reais das transportadoras no checkout e num widget na página do produto (POST /api/Customers/Simulate).
* **Rascunho de pedido automático** — o pedido pago é enviado à Vavi via POST /api/Customers/Integrations/Orders, sem digitar manualmente no portal.
* **Webhooks inbound** — status de produção/entrega e código de rastreio chegam do portal e atualizam o pedido e o cliente automaticamente, com validação HMAC-SHA256.

Nasceu da integração da loja Santa Arte Católica e foi desacoplado para ser útil à comunidade. Código em https://github.com/Mrpac6689/vavi-woocommerce.

== Installation ==

1. Envie o zip em **Plugins → Adicionar novo → Enviar plugin** e ative.
2. Em **WooCommerce → Vavi / PrintBee**, preencha Client ID, Client Secret e (opcionalmente) Customer ID.
3. Clique em **Testar conexão** para validar as credenciais.
4. (Opcional) Clique em **Registrar webhook na Vavi** para criar o webhook via API, ou crie manualmente no portal apontando para `{SITE}/wp-json/vavi/v1/printbee-webhook`.
5. Marque **"Usar frete real Vavi (por CEP)"** nos produtos desejados e adicione o método de envio **Vavi / PrintBee** à sua zona de frete.

Alternativa mais segura: defina as constantes `VAVI_CLIENT_ID`, `VAVI_CLIENT_SECRET`, `VAVI_WEBHOOK_SECRET`, `VAVI_CUSTOMER_ID` no `wp-config.php` — têm prioridade sobre a tela.

== Frequently Asked Questions ==

= Preciso de conta na Vavi? =

Sim. O plugin consome a API da Vavi Estampas/PrintBee. Sem credenciais válidas, o frete não é calculado.

= O plugin envia dados para fora? =

Sim, apenas para a API oficial da Vavi (https://api.printbee.com.br): cadastro de pedidos pagos e simulação de frete. Nenhum outro destino.

= Como desativar? =

Desative o plugin. A loja volta ao método de frete padrão da sua zona; pedidos já enviados à Vavi permanecem válidos.

== Changelog ==

= 1.0.0 =
* Versão inicial: frete real por CEP, rascunho de pedido, webhooks inbound com status/rastreio, tela de configuração e página "Sobre".

== Upgrade Notice ==

= 1.0.0 =
Versão inicial estável para o diretório de plugins.

== Screenshots ==

1. Tela de configuração WooCommerce → Vavi / PrintBee.
2. Widget "Calcule o frete para o seu CEP" na página do produto.
