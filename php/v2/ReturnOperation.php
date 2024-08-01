<?php

namespace NW\WebService\References\Operations\Notification;

use Exception;

class ReturnOperation extends ReferencesOperation
{
    public const TYPE_NEW = 1;
    public const TYPE_CHANGE = 2;

    /**
     * @throws Exception
     */
    public function doOperation(): array
    {
        $data = $this->getRequestData();
        $this->validateData($data);

        $resellerId = (int)$data['resellerId'];
        $reseller = Seller::getById($resellerId);
        $client = $this->getClient($data);

        $templateData = $this->prepareTemplateData($data, $client);
        $this->validateTemplateData($templateData);

        $result = $this->sendNotifications($resellerId, $client, $templateData, (int)$data['notificationType'], $data);

        return $result;
    }

    private function getRequestData(): array
    {
        return (array)$this->getRequest('data');
    }

    /**
     * @throws Exception
     */
    private function validateData(array $data): void
    {
        if (empty($data['resellerId'])) {
            throw new Exception('Empty resellerId', 400);
        }

        if (empty($data['notificationType'])) {
            throw new Exception('Empty notificationType', 400);
        }
    }

    /**
     * @throws Exception
     */
    private function getClient(array $data): Contractor
    {
        $client = Contractor::getById((int)$data['clientId']);
        if ($client->type !== Contractor::TYPE_CUSTOMER || $client->Seller->id !== (int)$data['resellerId']) {
            throw new Exception('Client not found!', 400);
        }
        return $client;
    }

    private function prepareTemplateData(array $data, Contractor $client): array
    {
        $cFullName = $client->getFullName() ?: $client->name;
        $cr = Employee::getById((int)$data['creatorId']);
        $et = Employee::getById((int)$data['expertId']);
        $differences = $this->getDifferences($data);

        return [
            'COMPLAINT_ID' => (int)$data['complaintId'],
            'COMPLAINT_NUMBER' => (string)$data['complaintNumber'],
            'CREATOR_ID' => (int)$data['creatorId'],
            'CREATOR_NAME' => $cr->getFullName(),
            'EXPERT_ID' => (int)$data['expertId'],
            'EXPERT_NAME' => $et->getFullName(),
            'CLIENT_ID' => (int)$data['clientId'],
            'CLIENT_NAME' => $cFullName,
            'CONSUMPTION_ID' => (int)$data['consumptionId'],
            'CONSUMPTION_NUMBER' => (string)$data['consumptionNumber'],
            'AGREEMENT_NUMBER' => (string)$data['agreementNumber'],
            'DATE' => (string)$data['date'],
            'DIFFERENCES' => $differences,
        ];
    }

    /**
     * @throws Exception
     */
    private function validateTemplateData(array $templateData): void
    {
        foreach ($templateData as $key => $tempData) {
            if (empty($tempData)) {
                throw new Exception("Template Data ({$key}) is empty!", 500);
            }
        }
    }

    private function getDifferences(array $data): string
    {
        if ((int)$data['notificationType'] === self::TYPE_NEW) {
            return __('NewPositionAdded', null, (int)$data['resellerId']);
        } elseif ((int)$data['notificationType'] === self::TYPE_CHANGE && !empty($data['differences'])) {
            return __('PositionStatusHasChanged', [
                'FROM' => Status::getName((int)$data['differences']['from']),
                'TO' => Status::getName((int)$data['differences']['to']),
            ], (int)$data['resellerId']);
        }
        return '';
    }

    private function sendNotifications(int $resellerId, Contractor $client, array $templateData, int $notificationType, array $data): array
    {
        $result = [
            'notificationEmployeeByEmail' => false,
            'notificationClientByEmail' => false,
            'notificationClientBySms' => [
                'isSent' => false,
                'message' => '',
            ],
        ];

        $this->notifyEmployees($resellerId, $templateData, $result);
        if ($notificationType === self::TYPE_CHANGE) {
            $this->notifyClient($resellerId, $client, $templateData, $data, $result);
        }

        return $result;
    }

    private function notifyEmployees(int $resellerId, array $templateData, array &$result): void
    {
        $emailFrom = getResellerEmailFrom($resellerId);
        $emails = getEmailsByPermit($resellerId, 'tsGoodsReturn');
        if (!empty($emailFrom) && count($emails) > 0) {
            foreach ($emails as $email) {
                MessagesClient::sendMessage([
                    [
                        'emailFrom' => $emailFrom,
                        'emailTo' => $email,
                        'subject' => __('complaintEmployeeEmailSubject', $templateData, $resellerId),
                        'message' => __('complaintEmployeeEmailBody', $templateData, $resellerId),
                    ],
                ], $resellerId, NotificationEvents::CHANGE_RETURN_STATUS);
                $result['notificationEmployeeByEmail'] = true;
            }
        }
    }

    private function notifyClient(int $resellerId, Contractor $client, array $templateData, array $data, array &$result): void
    {
        $emailFrom = getResellerEmailFrom($resellerId);
        if (!empty($emailFrom) && !empty($client->email)) {
            MessagesClient::sendMessage([
                [
                    'emailFrom' => $emailFrom,
                    'emailTo' => $client->email,
                    'subject' => __('complaintClientEmailSubject', $templateData, $resellerId),
                    'message' => __('complaintClientEmailBody', $templateData, $resellerId),
                ],
            ], $resellerId, $client->id, NotificationEvents::CHANGE_RETURN_STATUS, (int)$data['differences']['to']);
            $result['notificationClientByEmail'] = true;
        }

        if (!empty($client->mobile)) {
            $error = '';
            $res = NotificationManager::send($resellerId, $client->id, NotificationEvents::CHANGE_RETURN_STATUS, (int)$data['differences']['to'], $templateData, $error);
            if ($res) {
                $result['notificationClientBySms']['isSent'] = true;
            }
            if (!empty($error)) {
                $result['notificationClientBySms']['message'] = $error;
            }
        }
    }
}
