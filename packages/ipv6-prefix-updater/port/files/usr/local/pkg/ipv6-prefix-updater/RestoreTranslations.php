<?php

declare(strict_types=1);

/**
 * Package-owned translations for full configuration restore recovery.
 *
 * The lifecycle sync uses these messages for its reassurance notice and the
 * configuration revision that maintains the persistent recovery cron entry.
 *
 * @since 2.1.2
 */

$restoreEnglish = [
    'restore_reassurance_notice' => 'IPv6 Prefix Updater is still installed and its settings are unchanged. pfSense reported it as removed after the configuration restore only because it isn\'t installed from Netgate\'s package repository. No action is needed.',
    'restore_heal_cron_revision' => 'IPv6 Prefix Updater refreshed configuration-restore recovery.',
];

$restoreBosnian = [
    'restore_reassurance_notice' => 'Ažuriranje IPv6 prefiksa je i dalje instalirano i njegove postavke nisu promijenjene. pfSense je nakon vraćanja konfiguracije prijavio da je uklonjeno samo zato što nije instalirano iz Netgateovog repozitorija paketa. Nije potrebna nikakva radnja.',
    'restore_heal_cron_revision' => 'Ažuriranje IPv6 prefiksa osvježilo je oporavak nakon vraćanja konfiguracije.',
];

$restoreChineseSimplified = [
    'restore_reassurance_notice' => 'IPv6 前缀更新器仍处于安装状态，其设置没有变化。pfSense 在恢复配置后将其报告为已删除，只是因为它并非从 Netgate 软件包存储库安装。无需执行任何操作。',
    'restore_heal_cron_revision' => 'IPv6 前缀更新器已刷新配置恢复保护。',
];

$restoreChineseTraditional = [
    'restore_reassurance_notice' => 'IPv6 前綴更新器仍然保持安裝，其設定沒有變更。pfSense 在還原設定後將它報告為已移除，只是因為它並非從 Netgate 套件存放庫安裝。不需要採取任何動作。',
    'restore_heal_cron_revision' => 'IPv6 前綴更新器已重新整理設定還原復原項目。',
];

$restoreDutch = [
    'restore_reassurance_notice' => 'IPv6 Prefix Updater is nog steeds geïnstalleerd en de instellingen zijn ongewijzigd. pfSense meldde het pakket na het configuratieherstel alleen als verwijderd omdat het niet uit Netgate\'s pakketrepository is geïnstalleerd. Er is geen actie nodig.',
    'restore_heal_cron_revision' => 'IPv6 Prefix Updater heeft configuratieherstel bijgewerkt.',
];

$restoreFrench = [
    'restore_reassurance_notice' => 'IPv6 Prefix Updater est toujours installé et ses réglages sont inchangés. pfSense l’a signalé comme supprimé après la restauration de la configuration uniquement parce qu’il n’est pas installé depuis le dépôt de paquets de Netgate. Aucune action n’est nécessaire.',
    'restore_heal_cron_revision' => 'IPv6 Prefix Updater a actualisé la récupération après restauration de configuration.',
];

$restoreGerman = [
    'restore_reassurance_notice' => 'IPv6 Prefix Updater ist weiterhin installiert und seine Einstellungen sind unverändert. pfSense meldete das Paket nach der Wiederherstellung der Konfiguration nur deshalb als entfernt, weil es nicht aus dem Paket-Repository von Netgate installiert wurde. Es ist keine Aktion erforderlich.',
    'restore_heal_cron_revision' => 'IPv6 Prefix Updater hat die Wiederherstellung nach einer Konfigurationswiederherstellung aktualisiert.',
];

$restoreItalian = [
    'restore_reassurance_notice' => 'IPv6 Prefix Updater è ancora installato e le sue impostazioni non sono cambiate. pfSense lo ha segnalato come rimosso dopo il ripristino della configurazione solo perché non è installato dal repository dei pacchetti Netgate. Non è necessaria alcuna azione.',
    'restore_heal_cron_revision' => 'IPv6 Prefix Updater ha aggiornato il ripristino dopo il recupero della configurazione.',
];

$restoreKorean = [
    'restore_reassurance_notice' => 'IPv6 접두사 업데이터는 여전히 설치되어 있으며 설정도 변경되지 않았습니다. pfSense가 구성 복원 후 제거된 것으로 보고한 이유는 Netgate 패키지 저장소에서 설치되지 않았기 때문입니다. 별도의 조치는 필요하지 않습니다.',
    'restore_heal_cron_revision' => 'IPv6 접두사 업데이터가 구성 복원 복구 항목을 새로 고쳤습니다.',
];

$restoreNorwegian = [
    'restore_reassurance_notice' => 'IPv6 Prefix Updater er fortsatt installert, og innstillingene er uendret. pfSense meldte pakken som fjernet etter konfigurasjonsgjenopprettingen bare fordi den ikke er installert fra Netgates pakkelager. Ingen handling er nødvendig.',
    'restore_heal_cron_revision' => 'IPv6 Prefix Updater oppdaterte gjenoppretting etter konfigurasjonsrestore.',
];

$restorePolish = [
    'restore_reassurance_notice' => 'IPv6 Prefix Updater jest nadal zainstalowany, a jego ustawienia nie uległy zmianie. pfSense zgłosił jego usunięcie po przywróceniu konfiguracji tylko dlatego, że pakiet nie został zainstalowany z repozytorium Netgate. Nie trzeba podejmować żadnych działań.',
    'restore_heal_cron_revision' => 'IPv6 Prefix Updater odświeżył odzyskiwanie po przywróceniu konfiguracji.',
];

$restorePortuguese = [
    'restore_reassurance_notice' => 'O IPv6 Prefix Updater continua instalado e as respetivas definições não foram alteradas. O pfSense indicou que tinha sido removido após o restauro da configuração apenas porque não está instalado a partir do repositório de pacotes da Netgate. Não é necessária qualquer ação.',
    'restore_heal_cron_revision' => 'O IPv6 Prefix Updater atualizou a recuperação após o restauro da configuração.',
];

$restorePortugueseBrazil = [
    'restore_reassurance_notice' => 'O IPv6 Prefix Updater continua instalado e suas configurações não foram alteradas. O pfSense informou que ele foi removido após a restauração da configuração apenas porque não está instalado pelo repositório de pacotes da Netgate. Nenhuma ação é necessária.',
    'restore_heal_cron_revision' => 'O IPv6 Prefix Updater atualizou a recuperação após a restauração da configuração.',
];

$restoreRussian = [
    'restore_reassurance_notice' => 'IPv6 Prefix Updater по-прежнему установлен, а его настройки не изменились. pfSense сообщил о его удалении после восстановления конфигурации только потому, что пакет установлен не из репозитория Netgate. Никаких действий не требуется.',
    'restore_heal_cron_revision' => 'IPv6 Prefix Updater обновил восстановление после загрузки конфигурации.',
];

$restoreSpanish = [
    'restore_reassurance_notice' => 'IPv6 Prefix Updater sigue instalado y sus ajustes no han cambiado. pfSense indicó que se había eliminado después de restaurar la configuración únicamente porque no está instalado desde el repositorio de paquetes de Netgate. No es necesario hacer nada.',
    'restore_heal_cron_revision' => 'IPv6 Prefix Updater actualizó la recuperación tras restaurar la configuración.',
];

$restoreSpanishArgentina = [
    'restore_reassurance_notice' => 'IPv6 Prefix Updater sigue instalado y su configuración no cambió. pfSense informó que se había eliminado después de restaurar la configuración únicamente porque no está instalado desde el repositorio de paquetes de Netgate. No hace falta realizar ninguna acción.',
    'restore_heal_cron_revision' => 'IPv6 Prefix Updater actualizó la recuperación después de restaurar la configuración.',
];

return [
    'bs' => $restoreBosnian,
    'zh_CN' => $restoreChineseSimplified,
    'zh_Hans_CN' => $restoreChineseSimplified,
    'zh_Hans_HK' => $restoreChineseSimplified,
    'zh_Hant_TW' => $restoreChineseTraditional,
    'nl_NL' => $restoreDutch,
    'en_US' => $restoreEnglish,
    'fr_FR' => $restoreFrench,
    'de_DE' => $restoreGerman,
    'it_IT' => $restoreItalian,
    'ko_FR' => $restoreKorean,
    'ko_KR' => $restoreKorean,
    'nb_NO' => $restoreNorwegian,
    'pl_PL' => $restorePolish,
    'pt_PT' => $restorePortuguese,
    'pt_BR' => $restorePortugueseBrazil,
    'ru_RU' => $restoreRussian,
    'es_ES' => $restoreSpanish,
    'es_AR' => $restoreSpanishArgentina,
];
