<?php
include 'class.audio_streamer.php';
$sound = new audio_streamer('./test.mp3');
$sound->save();
?>