# Nome de arquivo: o que guardamos e o que o cliente vê

> Escrito para quem mexe em upload, envio de mídia ou nos handlers de canal.

## O problema que isso resolve

O último segmento de um caminho salvo **não é detalhe interno** — é um nome que
gente lê. Quatro superfícies derivam dele, e nenhuma tem outra fonte:

| Superfície | Quem lê |
|---|---|
| Bolha de documento no painel (`DocumentMessage`) | atendente |
| "Salvar arquivo" (`mediaFileName`) | atendente |
| `MediaFileController` serve o arquivo | navegador nomeia o download |
| `OutboundMedia::fromData()` lê o basename da `media_url` | **o cliente** |

A última linha é a que mais doeu. O nome vira o `filename` anunciado ao canal —
e no **Instagram** e no **Messenger** ele é o *único* nome que existe, porque
esses dois não aceitam campo de nome nenhum (o `filename` que gravamos em `meta`
nunca sai daqui).

Então `media/4812_68d1a2f3b4c5d.pdf` e `uploads/8f3a2b1c9d….pdf` eram o que o
atendente baixava e o que o cliente era convidado a abrir.

## A regra

Tudo passa por **`App\Services\Media\MediaFilename::path()`**:

```php
MediaFilename::path(
    'media',                    // a área
    (string) $message->id,      // o que torna ESTE arquivo único
    $originalName,              // o nome que o arquivo trouxe
    $extension,                 // vindo do CONTEÚDO, nunca do nome
    $messageType->value,        // fallback quando não há nome
);
// → media/4812/Contrato de Serviço.pdf
```

**O nome fica inteiro** — espaços, acentos, parênteses — e sem nada colado nele.
Duas regras sustentam isso:

> ⚠️ **1. A unicidade mora no diretório, nunca no nome.**

Não é opcional. `media/` é plano e compartilhado por **todos os tenants**: duas
empresas enviando `catalogo.pdf` cairiam no mesmo caminho — a segunda escrita
substitui a primeira, e o fluxo da primeira segue mandando o arquivo de outra
pessoa. Dentro de um mesmo workspace não é melhor: um histórico de conversa
precisa ser imutável, e um segundo `Proposta.pdf` não pode mudar retroativamente
o que uma conversa antiga mostra.

A **galeria** funciona assim desde que nasceu (o uuid está no caminho e
`public_filename` é o nome puro) — o resto da plataforma só alcançou ela.

> ⚠️ **2. Quem encoda é a URL, não o nome.**

Um espaço não é caractere de URI e um byte UTF-8 cru também não. A resposta a
isso é `MediaStorage::encodePath()` **na hora de montar a URL**, e
`rawurldecode()` em quem lê uma URL de volta — não mutilar o nome guardado.
Mutilar era o conserto barato: fazia toda superfície pagar pela regra de uma
fronteira só.

| Onde | O que acontece |
|---|---|
| `MediaStorage::signedUrl()` | já encodava (rota assinada); a assinatura cobre a URL encodada |
| `publishedUrl()` / `outboundUrl()` (disco local) | `encodePath()` — era aqui que saía URL com espaço cru |
| `publishedUrl()` (bucket público) | `encodePath()`: do lado do Laravel é concatenação pura |
| `temporaryUrl()` (presigned) | **nunca** pré-encodar — o SDK encoda a key e assina; encodar duas vezes assina uma key que o bucket não tem |
| `OutboundMedia::fromData()` | `rawurldecode()` — esse basename é o `filename` anunciado ao canal |
| `WidgetController::resolveAttachmentPath()` | `rawurldecode()` — o disco conhece o arquivo pelo nome real |
| `McpMediaUploads` | `rawurldecode()` ao nomear um arquivo remoto pela URL |

⚠️ `rawurlencode`, nunca `urlencode`: o segundo escreve espaço como `+`, que é
sintaxe de formulário e dentro de um path significa um mais literal.

⚠️ Por segmento, nunca a string toda: as barras são estrutura do caminho.

Testes: `tests/Feature/Media/MediaUrlEncodingTest.php` cobre as duas direções,
incluindo o round-trip da URL assinada — a falha que transformaria todo anexo
em 403.

### O que ainda sai do nome

Só o que um caminho não carrega: caracteres de controle, os separadores, o que o
Windows recusa num nome de arquivo (`: * ? " < > |`) e os três estruturais numa
URL (`#`, `?`, `%` — o último porque um percent literal deixa ambíguo qualquer
decode que aconteça sem um encode correspondente). Espaço, acento, parêntese e
`&` sobrevivem.

O limite é em **bytes** (150), não em caracteres: uma letra acentuada são dois, e
tanto o limite de 255 bytes por segmento de caminho quanto o de 1024 bytes por
key de object storage contam bytes. O corte é `mb_strcut`, porque cortar no meio
de uma letra deixa um nome que o cast JSON de `messages.meta` recusa.

### Chave natural vs. token

Prefira uma **chave natural**: com `$message->id`, uma tentativa repetida de
`DownloadInboundMedia` (são 3) sobrescreve a própria tentativa anterior em vez de
deixar órfão no disco.

Use **`MediaFilename::token()`** (12 hex) onde não existe chave: cópias públicas
temporárias e mídia de post do Instagram. ⚠️ As temporárias precisam de
diretório próprio mesmo vivendo segundos: dois envios do mesmo nome
compartilhariam o caminho, e a limpeza de um apaga o arquivo no meio do fetch do
outro.

E-mail é o caso misto: um e-mail carrega vários anexos, e **dois anexos de um
mesmo e-mail podem ter o mesmo nome** (todo inline do Outlook é `image001.png`),
então a posição entra como diretório — `media/{message_id}/{índice}/{nome}`.

## `uploads/`: uma pasta por workspace

`POST /api/uploads` (nós de fluxo, cartões de carrossel, campanhas) e as
ferramentas MCP gravam em **`uploads/{tenant}/{nome}`** via `PublishedUpload`.
A pasta é o isolamento, então o nome nu serve de endereço:
`uploads/3/Contrato de Serviço.pdf`.

⚠️ A consequência é que **repetir um nome dentro de um workspace passa a ser uma
colisão de verdade** — e ela é *respondida*, não adivinhada
(`App\Enums\Media\UploadConflict`). Adivinhar é o bug nas duas direções:
substituir em silêncio repõe o que todo nó de fluxo naquela URL manda, e renomear
em silêncio deixa alguém procurando o arquivo que achou que tinha atualizado.

| `on_conflict` | O que acontece |
|---|---|
| `cancel` (padrão do dashboard) | **409** `file_exists`, com nome, tamanho e data do que já está lá |
| `replace` | sobrescreve, **mesma URL** — muda o que todo fluxo/carrossel/campanha naquela URL passa a enviar |
| `rename` (padrão do MCP) | `catalogo (2).pdf`, a numeração de qualquer gerenciador de arquivos |

**Por que os padrões diferem:** no dashboard há uma pessoa esperando, e as duas
outras respostas têm consequências que ela deve escolher. No MCP não há ninguém
no prompt: falhar gasta um turno numa colisão de nome, e substituir poderia
repor um fluxo que o modelo nunca foi pedido para mexer. `rename` é a única
resposta sempre segura e sempre bem-sucedida.

⚠️ `PublishedUpload::store()` grava sob `Cache::lock("uploads:store:{tenant}")`,
pelo mesmo motivo que a galeria: dois uploads do mesmo nome chegando juntos
achariam `(2)` livre os dois e escreveriam o mesmo arquivo. Perder um arquivo
para uma corrida é exatamente a falha que todo esse esquema existe para evitar.

⚠️ O MCP decide na **criação do link** (`create_upload_link`), não no resgate: o
resgate é um `curl -F file=@…` de um shell, sem onde dizer o que uma colisão
significa.

FE: `contexts/MediaUploadContext.tsx` + `components/media/UploadConflictModal.tsx`.
Um uploader para os cinco campos de mídia do dashboard — cinco cópias do diálogo
seriam cinco chances de um deles substituir um arquivo em silêncio.

## Dois nomes, e eles não são redundantes

| | Onde | Como é |
|---|---|---|
| `messages.meta.filename` | só onde o canal informou um nome | intacto |
| último segmento de `messages.attachment` | toda linha | intacto, menos o que um caminho não carrega |

Os dois agora quase sempre coincidem — o caminho não precisa mais entregar nada,
porque a URL encoda. Ainda divergem quando o nome tinha um caractere que um
caminho não aceita, então o SPA prefere o primeiro e cai para o segundo
(`mediaFileName`, `documentFileName`). Linhas antigas, de antes disso existir,
só têm o segundo — e é por isso que o fallback não pode sair.

`MessageResource` publica `meta.filename` **fora** do `match` por canal, pelo
mesmo motivo que `transcription`: o nome pertence ao arquivo, não ao canal que o
carregou.

> ⚠️ `getFilenameMeta()` aplica `basename()`. Essa string chega a um atributo
> `download` no navegador; um nome carregando caminho seria o remetente
> escolhendo onde o arquivo cai.

## Quem informa o nome, por canal

| Canal | Entrada | Saída |
|---|---|---|
| WhatsApp Official | `document.filename` | `getClientOriginalName()` |
| WhatsApp API Way | `fileName`/`FileName`/`title` do nó whatsmeow | `getClientOriginalName()` |
| Telegram | `file_name` (documento, áudio, vídeo) | `getClientOriginalName()` |
| Discord | `attachment.filename` | `getClientOriginalName()` |
| Instagram, Messenger | **nada** (Meta não manda) | `getClientOriginalName()` |
| TikTok | **nada** (só imagem) | `getClientOriginalName()` |
| E-mail | nome da parte MIME | `getClientOriginalName()` |
| Widget | nome do visitante, no caminho do upload | — |

Sem nome, o fallback é `$messageType->value` → `media/4812/image.jpg`. É o máximo
que o nome pode dizer com honestidade, e ainda é melhor que um hash.

> ⚠️ A casing do payload do whatsmeow **não é consistente** (`URL`, `mediaKey` e
> `mimetype` convivem), por isso o lado API Way tenta as quatro grafias.

## Deliberadamente fora

- **Galeria** (`GalleryService::publicFilename`) — já fazia isso desde sempre, e
  é o precedente que o resto agora segue. `public_filename` é **imutável**: está
  assinado em toda URL já entregue ao WhatsApp e aos fetchers da Meta.
- **Avatares e fotos de contato** — são imagens de perfil buscadas do canal; não
  existe nome original. E a validade da URL assinada do avatar é arredondada de
  propósito, para o mesmo rosto não ser rebaixado a cada serialize.
- **Áudio de resposta da IA** (`resposta-*.ogg`) — sintetizado; não há nome a
  preservar.
- **Corpo HTML de e-mail** (`…_body.html`) — é o conteúdo da mensagem, não um
  anexo, e nunca foi nomeado por ninguém.
- **Gravação do test bench de vocabulário** — vive só durante a run, e a run já
  manda um `name` explícito para o hub.

## O que NÃO mudou

- **URLs já salvas continuam válidas.** Só escritas novas mudam de forma; nada
  faz parsing do formato antigo (verificado), a rota `/storage/{path}` já aceita
  `.*`, todas as listagens de diretório em `app/` já são recursivas
  (`media:purge`, `media:migrate`, `media:scan-unsafe-uploads`), e
  `resolveAttachmentPath` do widget já devolve tudo depois do marcador.
- **Nenhuma migration.** Linhas antigas ficam como estão e continuam sendo
  servidas. Para melhorá-las existe um comando, abaixo.

## Devolver o nome a arquivos que já estão no disco

```
php artisan media:restore-filenames --dry-run
php artisan media:restore-filenames [--tenant=] [--limit=500]
```

Três formatos estão no disco, de três épocas, e só o mais novo é legível:

```
media/4812_68d1a2f3b4c5d.pdf              — só código, nenhum nome
media/Comprovante-de-Pagamento_4812.pdf   — nome mais um código
media/4812/Comprovante de Pagamento.pdf   — o nome, e nada mais
```

O que torna o primeiro recuperável é **`messages.meta.filename`**: o canal nos
disse o nome real na época e a gente guardou. Então isso é menos um rename e mais
um *restore* — acentos, espaços e maiúsculas voltam com ele.

⚠️ O arquivo é **movido para o formato novo de diretório**, não renomeado no
lugar. Renomear `media/Comprovante_4812.pdf` para `media/Comprovante.pdf`
colocaria um nome nu na pasta que todo tenant compartilha, que é a colisão que o
diretório existe para evitar.

⚠️ O padrão de strip é **estreito de propósito**: só o id da mensagem ou um token
de 12 hex, os dois sufixos que esta plataforma realmente escreveu. Um padrão
solto comeria o fim de um nome real — `Relatorio_2026.pdf` é o arquivo de alguém,
e um comando que arranca o ano dele é pior do que um que não faz nada.

⚠️ `uploads/` é deixado de lado de propósito, e o comando conta quantos e diz por
quê: aqueles endereços estão escritos dentro de nós de fluxo, cartões de
carrossel, campanhas e itens de post do Instagram — JSON que teria de ser
reescrito em lockstep. `media:scan-unsafe-uploads` já existe justamente porque as
URLs daquela pasta podem estar em lugares que a gente não controla.

Idempotente, `--dry-run` primeiro. Não mexe em `updated_at` (é o cursor de delta
sync, e um caminho mais arrumado não é mudança que dashboard nenhum precisa
receber). Testes: `tests/Feature/Media/RestoreMediaFilenamesTest.php`.

## Se precisar mexer

Testes: `tests/Feature/Media/MediaFilenameTest.php` (a regra, as duas metades) e
`tests/Feature/Message/MediaFileNamingTest.php` (ponta a ponta, entrada e saída).

⚠️ Ao escrever um arquivo novo, a pergunta não é "que sufixo uso" — é **"qual
diretório é só deste arquivo"**. Se a resposta for "nenhum", é `token()`.

⚠️ Verificado em teste: encode por segmento, `%20` e nunca `+`, round-trip da URL
assinada, o nome decodificado anunciado ao canal e o anexo de widget resolvido a
partir de uma URL encodada. **Não** verificado em produção: o `handle_path
/storage*` do Caddy repassando um path encodado ao bucket, e presigning de key
com espaço/UTF-8 no Vultr — os dois só existem depois que a migração de object
storage roda, e valem um teste manual com um arquivo de nome acentuado na
primeira vez.
