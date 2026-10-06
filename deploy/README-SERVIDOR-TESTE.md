# Servidor de teste — ajustes de infraestrutura

Ajustes feitos **no servidor** de teste (VPS `137.184.224.89`, `/opt/apps/trade-teste`) que não
ficam no código da aplicação. Se o ambiente for recriado ou migrado, é por aqui que se refaz.

## Como o ambiente está montado

| Domínio | Container | Porta no host |
|---|---|---|
| `trade-teste.prossigatec.com.br` (admin) | `tradeteste_admin` | `127.0.0.1:8082` |
| `trade-teste-api.prossigatec.com.br` (API) | `tradeteste_api` | `127.0.0.1:9002` |

- **TLS e domínio:** nginx do **host** (não o de dentro dos containers), com certificado do
  Certbot. Sites em `/etc/nginx/sites-available/trade-teste*.prossigatec.com.br`.
- **Containers:** só escutam em `127.0.0.1`; quem atende de fora é o nginx do host.
- **Banco:** Postgres em outro container (`postgres`, rede `postgres-docker_default`), banco
  `trade_teste`.

## Cabeçalhos de segurança HTTP

Configurados no nginx do host, em dois arquivos incluídos no bloco `listen 443` de cada site:

| Arquivo no servidor | Cópia versionada |
|---|---|
| `/etc/nginx/snippets/seguranca-trade-teste-admin.conf` | [`nginx-host/seguranca-trade-teste-admin.conf`](nginx-host/seguranca-trade-teste-admin.conf) |
| `/etc/nginx/snippets/seguranca-trade-teste-api.conf` | [`nginx-host/seguranca-trade-teste-api.conf`](nginx-host/seguranca-trade-teste-api.conf) |

Cada site tem esta linha logo abaixo de `client_max_body_size`:

```nginx
include /etc/nginx/snippets/seguranca-trade-teste-admin.conf;   # ou ...-api.conf
```

### O que está ativo

| Cabeçalho | Admin | API |
|---|---|---|
| `Strict-Transport-Security` | `max-age=86400` (1 dia) | `max-age=86400` |
| `X-Frame-Options` | `DENY` | `DENY` |
| `X-Content-Type-Options` | `nosniff` | `nosniff` |
| `Referrer-Policy` | `strict-origin-when-cross-origin` | igual |
| `Permissions-Policy` | câmera, microfone, geolocalização, pagamento, USB desligados | igual |
| `Content-Security-Policy-Report-Only` | lista de origens do admin (abaixo) | `default-src 'none'` |
| `Server` | só `nginx`, sem versão (`server_tokens off`) | igual |

Na API, `proxy_hide_header` esconde o `X-Frame-Options`/`X-Content-Type-Options` que o nginx de
dentro do container já manda — senão o cabeçalho sairia duplicado.

**CSP do admin** (por que cada origem está liberada):

- `fonts.googleapis.com` / `fonts.gstatic.com` — fonte IBM Plex Sans do `index.html`.
- `*.tile.openstreetmap.org` — tiles dos mapas (Leaflet).
- `trade-teste-api.prossigatec.com.br` — chamadas da API e imagens.
- `blob:` / `data:` — fotos autenticadas baixadas pela API e PDFs abertos em nova aba.
- `*.digitaloceanspaces.com` — storage das fotos, caso alguma tela use a URL direta.
- `'unsafe-inline'` só em `style-src` — o MUI/Emotion injeta `<style>` em runtime.

### Pendências

1. **CSP ainda em Report-Only** (não bloqueia nada, só avisa no console do navegador). Usar o admin
   alguns dias com o console aberto (F12). Sem aviso `Content-Security-Policy-Report-Only`, trocar
   o nome do cabeçalho para `Content-Security-Policy` nos dois arquivos. Com aviso, incluir a
   origem que faltou na diretiva indicada.
   **Atenção na API:** desde o docs/58 a API também serve páginas HTML públicas
   (`/termos-de-uso` e `/politica-de-privacidade`, com `<style>` e Google Fonts). O
   `default-src 'none'` da API quebra o visual delas quando ativado. Antes de ativar, criar no site
   da API um `location ~ ^/(termos-de-uso|politica-de-privacidade)$` com CSP própria (liberando
   `style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src https://fonts.gstatic.com`)
   — lembrando que um `add_header` dentro do `location` faz ele não herdar os demais cabeçalhos do
   snippet, então repetir o `include` lá dentro.
2. **HSTS começou curto.** Depois de validar, subir para `max-age=31536000` (1 ano). Não usar
   `includeSubDomains` sem conferir todos os subdomínios de `prossigatec.com.br`.

### Como alterar e aplicar

```bash
# editar o arquivo do servidor e manter a cópia do repositório igual
sudo nano /etc/nginx/snippets/seguranca-trade-teste-admin.conf
sudo nginx -t && sudo systemctl reload nginx     # reload não derruba conexões nem outros sites
cp /etc/nginx/snippets/seguranca-trade-teste-*.conf deploy/nginx-host/
```

### Como conferir

```bash
curl -sI https://trade-teste.prossigatec.com.br/ | grep -iE 'strict|x-frame|x-content|referrer|permissions|content-security|^server'
curl -sI https://trade-teste-api.prossigatec.com.br/up | grep -iE 'strict|x-frame|x-content|referrer|permissions|content-security|^server'
```

Logo depois de um `reload`, a primeira requisição ainda pode cair num worker antigo — repetir.

### Como desfazer

Remover a linha `include /etc/nginx/snippets/seguranca-trade-teste-*.conf;` dos dois sites e
rodar `sudo nginx -t && sudo systemctl reload nginx`.

### Se o TLS sair do nginx do host

Se o admin/API forem para outro servidor, ou o TLS passar para outro proxy (Traefik, Caddy,
Cloudflare), estes cabeçalhos precisam ir junto: no proxy que faz o TLS (obrigatório para o
HSTS) ou nos `nginx.conf` das imagens do admin e da API.

## Documentação da API (`/docs/api`)

Gerada pelo `dedoc/scramble` a partir do código. Fora do `APP_ENV=local` ela só abre com
`API_DOCS_PUBLICA=true` no `.env` (gate `viewApiDocs`, ver `AppServiceProvider`). Neste servidor
está ligada; em produção, manter `false`.

- A geração leva ~10 s e usa ~120 MB de memória a cada acesso — por isso o `php.ini` abaixo.
- A página carrega scripts de CDN: com a CSP da API ativada de verdade (hoje em Report-Only), ela
  também precisa de exceção, igual às páginas dos termos.

## PHP do container

O `Dockerfile` desta branch copia `docker/php.ini` (o mesmo da `main`) para
`/usr/local/etc/php/conf.d/99-app.ini`: `memory_limit = 256M`, `upload_max_filesize = 20M`,
`post_max_size = 21M`, `max_execution_time = 60`, OPcache ligado. Antes (até 06/10/2026) a imagem
rodava com os defaults do PHP (128M de memória, upload de no máximo 2M).

## Outros pontos deste servidor

- **Fuso horário:** o servidor roda em **UTC** (sem `APP_TIMEZONE` no `.env`). O fuso de cada
  empresa fica em `empresas.fuso` (hoje `America/Manaus`) — ver docs/50.
- **Cron do Laravel:** o `Dockerfile` desta branch usa `deploy/supervisord.conf`, que **não** sobe
  o `crond`. Os `Schedule::command` de `routes/console.php` (geração de OS às 05:00, limpeza de
  histórico de GPS, cálculo de afastamento) não rodam sozinhos aqui.
