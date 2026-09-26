<?php

namespace App\Service\Import;

/**
 * Compteurs et messages d'un import de prospects.
 */
final class ImportReport
{
    public int $lignesLues = 0;

    public int $lignesIgnorees = 0;

    public int $prospectsCrees = 0;

    public int $prospectsMaj = 0;

    public int $contactsCrees = 0;

    public int $contactsMaj = 0;

    public int $actionsCreees = 0;

    public int $campagnesCreees = 0;

    public int $sprintsCrees = 0;

    public int $anomalies = 0;

    public int $departementsMajs = 0;

    /**
     * @var list<string>
     */
    public array $messages = [];

    public function ajouterMessage(string $message): void
    {
        $this->messages[] = $message;
    }

    /**
     * @return array<string, int|list<string>|string>
     */
    public function toArray(): array
    {
        return [
            'lignesLues' => $this->lignesLues,
            'lignesIgnorees' => $this->lignesIgnorees,
            'prospectsCrees' => $this->prospectsCrees,
            'prospectsMaj' => $this->prospectsMaj,
            'contactsCrees' => $this->contactsCrees,
            'contactsMaj' => $this->contactsMaj,
            'actionsCreees' => $this->actionsCreees,
            'campagnesCreees' => $this->campagnesCreees,
            'sprintsCrees' => $this->sprintsCrees,
            'departementsMajs' => $this->departementsMajs,
            'anomalies' => $this->anomalies,
            'messages' => $this->messages,
        ];
    }

    public function resume(): string
    {
        return sprintf(
            '%d lignes lues, %d ignorées | prospects %d créés / %d maj | contacts %d créés / %d maj | %d actions | %d campagnes | %d vagues | %d anomalies',
            $this->lignesLues,
            $this->lignesIgnorees,
            $this->prospectsCrees,
            $this->prospectsMaj,
            $this->contactsCrees,
            $this->contactsMaj,
            $this->actionsCreees,
            $this->campagnesCreees,
            $this->sprintsCrees,
            $this->anomalies,
        );
    }
}
