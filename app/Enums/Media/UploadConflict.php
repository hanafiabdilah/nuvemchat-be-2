<?php

namespace App\Enums\Media;

/**
 * What to do when a workspace uploads a name it already has.
 *
 * Every workspace has one folder (`uploads/{tenant}`), so a repeated name is a
 * real decision rather than a technicality — and it is the person's decision,
 * not ours. The three answers are the three a file manager gives.
 */
enum UploadConflict: string
{
    /**
     * Refuse, and say what is already there.
     *
     * The default on the dashboard, because there is a person at the other end
     * of it and the other two answers both have consequences they should choose.
     */
    case Cancel = 'cancel';

    /**
     * Overwrite, keeping the same URL.
     *
     * ⚠️ That URL is already written into flow nodes, carousel cards and
     * campaigns, so this changes what those send from now on — which is usually
     * exactly why someone picks it ("I fixed the catalogue"), and is never
     * something to do on their behalf. The dialog says so before the click.
     */
    case Replace = 'replace';

    /**
     * Keep both: the new file becomes `catalogo (2).pdf`.
     *
     * The default for MCP, where no human is waiting: failing the call wastes a
     * turn on a name collision, and replacing could repoint a flow the model
     * was never asked to touch.
     */
    case Rename = 'rename';

    /** @return list<string> */
    public static function values(): array
    {
        return array_map(fn (self $c) => $c->value, self::cases());
    }
}
