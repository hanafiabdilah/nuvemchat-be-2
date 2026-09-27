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

Então `media/4812_68d1a2f3b4c5d.pdf` e `uploads/8f3a2b1c9d4e5f6a….pdf` eram o que
o atendente baixava e o que o cliente era convidado a abrir. Um nome diz o que o
arquivo é; um hash não diz nada, e quem precisa agir sobre ele fica no escuro.

## A regra

Tudo passa por **`App\Services\Media\MediaFilename::build()`**:

```php
'uploads/'.MediaFilename::build(
    $file->getClientOriginalName(),        // o nome original
    UploadPolicy::storedExtension($file),  // extensão vinda do CONTEÚDO
    (string) $message->id,                 // chave natural, quando existe
    $messageType->value,                   // fallback quando não há nome
);
// → uploads/Contrato-de-Servico_4812.pdf
```

1. **O nome original sobrevive.** É o ponto.
2. **O código único vem depois dele, nunca no lugar dele.** `media/` e
   `uploads/` são planos e compartilhados por todos os tenants: dois clientes
   enviando `catalogo.pdf` não podem cair no mesmo caminho — a segunda escrita
   substituiria a primeira, e o flow do primeiro passaria a mandar o arquivo de
   outra pessoa.
3. **O resultado é ASCII.** Acentos são transliterados (`Relatório` →
   `Relatorio`), não removidos — apagar deixaria `Relatrio`, que parece erro de
   digitação. Não-ASCII sobreviveria a uma URL, mas essas chaves estão migrando
   para object storage, onde uma chave que atrapalha o presigning é uma queda
   silenciosa de mídia.
4. **Maiúsculas e `_` sobrevivem; espaço vira `-`.** Tudo que um caminho ou uma
   URL poderia ler como estrutura é removido.

## Dois nomes, e eles não são redundantes

| | Onde | Como é |
|---|---|---|
| `messages.meta.filename` | só onde o canal informou um nome | **intacto**: acentos, espaços, maiúsculas |
| último segmento de `messages.attachment` | toda linha | ASCII + sufixo único |

O SPA prefere o primeiro e cai para o segundo (`mediaFileName`,
`documentFileName`). Linhas antigas, de antes disso existir, só têm o segundo — e
é por isso que o fallback não pode sair.

`MessageResource` publica `meta.filename` **fora** do `match` por canal, pelo
mesmo motivo que `transcription`: o nome pertence ao arquivo, não ao canal que o
carregou.

> ⚠️ `getFilenameMeta()` aplica `basename()`. Essa string chega a um atributo
> `download` no navegador; um nome carregando caminho seria o remetente
> escolhendo onde o arquivo cai.

## Chave natural vs. código aleatório

Passe `$message->id` quando **um** arquivo pertence a **uma** linha: aí uma
tentativa repetida de `DownloadInboundMedia` (são 3) sobrescreve a própria
tentativa anterior em vez de deixar órfão no disco.

Não passe nada quando não há chave — `/api/uploads`, cópias públicas temporárias,
mídia de post do Instagram. Aí vêm 12 caracteres hex.

E-mail é o caso híbrido: um e-mail carrega vários anexos, então a chave é
`{message_id}-{índice no e-mail}`.

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

Sem nome, o fallback é `$messageType->value` → `image_4812.jpg`. É o máximo que
o nome pode dizer com honestidade, e ainda é melhor que um hash.

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

- **Extensão continua vindo do conteúdo** nos caminhos de upload
  (`UploadPolicy::storedExtension`) e do cliente nos handlers de canal — que é
  como já era, de propósito: lá o arquivo é temporário e vem do compositor de um
  atendente autenticado.
- **URLs já salvas continuam válidas.** Só escritas novas mudam de forma; nada
  faz parsing do formato antigo (verificado).
- **Nenhuma migration.** Linhas antigas ficam com o nome hasheado e continuam
  sendo servidas — o SPA lê o caminho que ela já tem.

## Se precisar mexer

Testes: `tests/Feature/Media/MediaFilenameTest.php` (a regra) e
`tests/Feature/Message/MediaFileNamingTest.php` (ponta a ponta, entrada e saída).

Pendente, consciente: um **`attachment_name`** no nó de flow deixaria o cliente
ver `Contrato de Serviço.pdf` em vez de `Contrato-de-Servico_a3f2b1c4d5e6.pdf` no
WhatsApp, porque aí o nome iria no campo `filename` do envio em vez de ser
deduzido da URL. Custa schema de flow (`FlowBlueprint`, `MessageNodes`,
`FlowExecutor`), o editor no FE e um override em cada handler de envio.
