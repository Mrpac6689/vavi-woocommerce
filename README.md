# Vavi WooCommerce

Plugin WordPress que integra qualquer loja **WooCommerce** com a **API da Vavi Estampas / PrintBee**:

- 🚚 **Frete real por CEP** — cotações reais das transportadoras no checkout e num widget na página do produto (via `POST /api/Customers/Simulate`).
- 📦 **Rascunho de pedido automático** — o pedido pago é enviado à Vavi (via `POST /api/Customers/Integrations/Orders`), sem digitar manualmente no portal.
- 🔔 **Webhooks inbound** — status de produção/entrega e código de rastreio chegam do portal e atualizam o pedido e o cliente automaticamente.

Projeto **open source** (GPL-2.0), genérico — funciona em qualquer loja WooCommerce que use a Vavi/PrintBee. Nasceu da integração da loja [Santa Arte Católica](https://santaartecatolica.com.br) e foi desacoplado para ser útil à comunidade.

---

## Requisitos

- WordPress 6.0+
- WooCommerce 7.0+ (testado até WooCommerce 11)
- PHP 7.4+
- Uma conta na **Vavi Estampas / PrintBee** com credenciais de API (`Client ID`/`Client Secret`)

---

## Instalação

1. Baixe o zip (aba *Releases*) ou clone o repositório.
2. Em **Plugins → Adicionar novo → Enviar plugin**, envie o `vavi-woocommerce.zip`.
3. Ative o plugin.

---

## Configuração

Acesse **WooCommerce → Vavi / PrintBee**:

| Campo | Descrição | Onde achar |
|---|---|---|
| **Client ID** | Identificador da API (começa com `pb_`) | Portal Vavi → Integrações/API |
| **Client Secret** | Segredo do cliente | Portal Vavi → Integrações/API |
| **Customer ID** | ID do cliente na Vavi (para registrar webhook via API) | Portal Vavi → conta |
| **Webhook Secret** | Segredo `whsec_...` gerado ao criar o webhook | Portal Vavi → Webhooks |

> **Alternativa em código (mais seguro):** antes de salvar na tela, o plugin lê, em ordem de prioridade, as constantes PHP ou variáveis de ambiente:
>
> ```php
> define( 'VAVI_CLIENT_ID', 'pb_...' );
> define( 'VAVI_CLIENT_SECRET', '...' );
> define( 'VAVI_WEBHOOK_SECRET', 'whsec_...' );
> define( 'VAVI_CUSTOMER_ID', '...' );
> ```
>
> Recomendado para quem prefere manter segredos fora do banco. Os campos da tela ficam com prioridade menor.

Botões na mesma tela:
- **Testar conexão** — valida credenciais obtendo um token (sem fazer nada destrutivo).
- **Registrar webhook na Vavi** — cria/atualiza o webhook via API apontando para o seu endpoint (requer Client ID/Secret + Customer ID).

---

## Configurar o frete por produto (migração progressiva)

O plugin adota o modelo **"frete por produto"** (não por zona fixa), permitindo migrar um produto de cada vez:

- No **Admin do produto**, marque **"Usar frete real Vavi (por CEP)"** (meta box lateral ou aba *Envio*).
- Enquanto o produto não estiver marcado, ele segue o comportamento padrão da loja (ex.: frete grátis).
- No checkout, se **qualquer** item do carrinho usa frete Vavi, as cotações reais aparecem (e o frete grátis é removido daquele carrinho — impede "burlar" o frete).

Depois de marcar o primeiro produto, adicione o método de envio **Vavi / PrintBee** à sua zona de frete em **WooCommerce → Envio → Zonas**.

---

## Como criar o webhook na Vavi

O webhook é o que faz o status do pedido e o rastreio voltarem do portal para a sua loja. Duas formas:

### Forma A — automática (recomendada)
1. Preencha **Client ID**, **Client Secret** e **Customer ID**.
2. Clique em **"Registrar webhook na Vavi"**.
3. O plugin cria o webhook via API apontando para:
   ```
   {SITE}/wp-json/vavi/v1/printbee-webhook
   ```
4. O portal gera/confirma um **Webhook Secret** — cole-o no campo correspondente.

### Forma B — manual (no portal)
1. No portal Vavi, acesse **Integrações / Webhooks**.
2. Cadastre a URL de destino:
   ```
   {SITE}/wp-json/vavi/v1/printbee-webhook
   ```
3. Selecione os eventos:
   - `order.created`
   - `order.status_changed`
   - `order.tracking`
   - `integration_order.received` / `converted` / `failed` / `discarded` / `reopened`
4. Guarde o **Webhook Secret** gerado (`whsec_...`) e cole no plugin.

> **Segurança:** o plugin valida cada envio com **HMAC-SHA256** (`X-Webhook-Signature`). Envios sem assinatura válida recebem **401** e são descartados. O endpoint responde 200 imediatamente e processa em background (cron), com **deduplicação por `webhookSentId`**.

---

## O que o plugin faz com os webhooks

| Evento Vavi | Ação no WooCommerce |
|---|---|
| `integration_order.received/failed/discarded/reopened` | Registra nota no pedido (rastreabilidade do rascunho) |
| `integration_order.converted` | Guarda `orderId`/`orderNSU` e registra nota |
| `order.created` | Guarda `orderId`/`orderNSU` do pedido real |
| `order.status_changed` | Aplica status customizado + e-mail ao cliente |
| `order.tracking` | Guarda código/URL de rastreio + atualiza status de envio |

Status customizados criados: **Aguardando Produção, Em Produção, Produção Concluída, Aguardando Pagamento Final, Despachado, Em Trânsito, Entregue, Extraviado, Devolvido**.

---

## Personalização (para desenvolvedores)

- **Campos de endereço** (número, bairro, CPF): filtro `vavi_address_meta_keys` (padrão: `_billing_number`, `_billing_neighborhood`, `_billing_cpf`).
- **Variações enviadas** (Cor, Tamanho): filtro `vavi_variation_meta_keys`.
- **Eventos do webhook**: filtro `vavi_webhook_events`.
- **Prefixo dos metas**: filtro `vavi_meta_prefix`.

---

## Roadmap / pendências conhecidas

- **Registro de webhook via API** — implantado; o formato exato dos eventos/campos deve ser confirmado no portal da Vavi (a spec documenta os eventos outbound, mas não o endpoint de cadastro).
- **Tradução** — os textos já usam `__()`/`esc_html_e()` com text domain `vavi-woocommerce`; falta gerar os arquivos `.pot`/`.po`.

---

## Reversão / desativação

Desativar o plugin **volta a loja ao comportamento anterior** (frete grátis / método padrão da sua zona) **sem perder** pedidos já enviados à Vavi. Os metas `_vavi_*` nos pedidos permanecem para referência futura.

---

## Licença

GPL-2.0-or-later — veja [`LICENSE`](LICENSE).
