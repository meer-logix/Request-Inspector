<?php
/** Add descriptions to existing typed API documentation. */
foreach (glob(dirname(__DIR__) . '/request-inspector/includes/*.php') as $file) {
    $source = file_get_contents($file);
    $source = preg_replace_callback('/\/\*\*\r?\n([ \t]*)\* (@[\s\S]*?)\*\/\s*((?:public|private|protected)(?: static)?(?: function)? [^\r\n{;]+)/', function ($match) {
        $declaration = $match[3];
        if (preg_match('/function (\w+)/', $declaration, $name)) {
            $summary = ucfirst(str_replace('_', ' ', $name[1])) . '.';
        } elseif (preg_match('/@var \S+ ([^\r\n]+)/', $match[2], $description)) {
            $summary = $description[1];
        } else {
            $summary = 'Internal recorder state.';
        }
        return "/**\n" . $match[1] . '* ' . $summary . "\n" . $match[1] . "*\n" . $match[1] . '* ' . $match[2] . '*/' . "\n" . rtrim($match[1]) . $declaration;
    }, $source);
    file_put_contents($file, $source);
}
