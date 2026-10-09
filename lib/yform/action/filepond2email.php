<?php

class rex_yform_action_filepond2email extends rex_yform_action_abstract
{
    public function executeAction(): void
    {
        $label_from = $this->getElement(2);

        foreach ($this->params['value_pool']['email'] as $key => $value) {
            if ($label_from === $key) {
                foreach (explode(',', (string) $value) as $filename) {
                    $filename = trim($filename);
                    // Nur Dateien aus dem Medienpool anhängen, keine beliebigen Pfade
                    if ('' !== $filename && basename($filename) === $filename && null !== rex_media::get($filename) && is_file(rex_path::media($filename))) {
                        $this->params['value_pool']['email_attachments'][] = [$filename, rex_path::media($filename)];
                    }
                }
                break;
            }
        }
    }

    public function getDescription(): string
    {
        return 'action|filepond2email|label_from';
    }
}
