<?php
header('Content-Type: text/plain; charset=utf-8');
echo 'OPENAI_API_KEY=' . (getenv('OPENAI_API_KEY') ? 'SET' : 'MISSING');
