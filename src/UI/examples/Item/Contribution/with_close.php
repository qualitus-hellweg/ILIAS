<?php declare(strict_types=1);

namespace ILIAS\UI\examples\Item\Contribution;

function with_close()
{
    global $DIC;

    return $DIC->ui()->renderer()->render(
        $DIC->ui()->factory()->item()->contribution(
            'a little test contribution',
            new \ilObjUser(6),
            new \ilDateTime(time(), IL_CAL_UNIX)
        )->withClose(
            $DIC->ui()->factory()->button()->close()
        )
    );
}
