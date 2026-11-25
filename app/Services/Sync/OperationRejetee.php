<?php

namespace App\Services\Sync;

use RuntimeException;

/** Une operation recue d'un poste est refusee ; le message est le motif renvoye au poste. */
class OperationRejetee extends RuntimeException {}
