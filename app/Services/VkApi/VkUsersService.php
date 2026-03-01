<?php

namespace App\Services\VkApi;

/**
 * VK Users API Service
 * Handles operations with users profiles.
 */
class VkUsersService
{
    private ?VkSdkAdapter $adapter = null;

    /**
     * Set SDK adapter instance (for testing)
     */
    public function setAdapter(?VkSdkAdapter $adapter): void
    {
        $this->adapter = $adapter;
    }

    /**
     * Get SDK adapter instance
     */
    private function getAdapter(): VkSdkAdapter
    {
        if ($this->adapter === null) {
            $this->adapter = new VkSdkAdapter();
        }

        return $this->adapter;
    }

    /**
     * Get users profiles by IDs.
     *
     * @param array<int> $userIds
     * @param array<string> $fields
     * @return array<int, array<string, mixed>>
     */
    public function getByIds(array $userIds, array $fields = ['screen_name']): array
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if (empty($userIds)) {
            return [];
        }

        $adapter = $this->getAdapter();
        $profiles = [];

        // users.get supports up to 1000 ids per call
        $chunks = array_chunk($userIds, 1000);
        foreach ($chunks as $chunk) {
            try {
                $result = $adapter->execute(function () use ($adapter, $chunk, $fields) {
                    return $adapter->users()->get(
                        $adapter->getToken(),
                        [
                            'user_ids' => $chunk,
                            'fields' => $fields,
                        ]
                    );
                }, 'getting users profiles');
            } catch (\Exception $e) {
                continue;
            }

            if (!is_array($result)) {
                continue;
            }

            foreach ($result as $row) {
                if (!is_array($row) || !isset($row['id'])) {
                    continue;
                }
                $profiles[(int) $row['id']] = $row;
            }
        }

        return $profiles;
    }
}

