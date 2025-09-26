<div align="center">
    <img src="logo.png" alt="Mailcow ISP" height="20%" width="20%" style="vertical-align: middle;">
</div>

<h1 align="center">Mailcow ISP</h1>

<p align="center">
    ⭐ If you like this project, <strong>consider giving it a star</strong> — it really keeps us motivated! ⭐<br>
    ⭐ Si ce projet vous plaît, <strong>pensez à lui mettre une étoile</strong> — ça nous motive énormément ! ⭐

<p>&nbsp;</p>

<p align="center">
  <a href="https://github.com/mailcow/mailcow-dockerized" target="_blank">
    <img src="https://img.shields.io/badge/MAILCOW-FFC107?style=for-the-badge&logoColor=white" alt="Mailcow"/>
  </a>
  <a href="https://www.docker.com/" target="_blank">
    <img src="https://img.shields.io/badge/Docker-2496ED?style=for-the-badge&logo=docker&logoColor=white" alt="Docker"/>
  </a>
  <a href="https://www.keepalived.org/" target="_blank">
    <img src="https://img.shields.io/badge/Keepalived-009688?style=for-the-badge" alt="Keepalived"/>
  </a>
  <a href="https://www.hetzner.com/cloud" target="_blank">
    <img src="https://img.shields.io/badge/Hetzner%20Cloud-D50C2D?style=for-the-badge&logo=hetzner&logoColor=white" alt="Hetzner"/>
  </a>
  <a href="https://www.gnu.org/software/bash/" target="_blank">
    <img src="https://img.shields.io/badge/GNU%20Bash-4EAA25?style=for-the-badge&logo=gnubash&logoColor=white" alt="GNU Bash"/>
  </a>
<br><br>
</p>

<details>
<summary><strong>🇬🇧 English Version (click to expand)</strong></summary>

### 📄 Detailed Technical Architecture

### 1. Philosophy and Objectives

A standard Mailcow instance, while powerful, is a **Single Point of Failure (SPOF)**. A hardware failure, a faulty update, or a critical container crash is enough to bring down the entire mail service.

The goal of **Mailcow-HA** is to transform this single instance into a **resilient Active/Passive cluster**, capable of surviving most failures by automatically failing over the service to a standby node, with zero human intervention and minimal service interruption.

This solution is designed as an **orchestration layer** that integrates with a standard Mailcow installation without modifying its core, ensuring full compatibility with future Mailcow updates.

---

### 2. The Four Pillars of the Infrastructure

The cluster's robustness is built on four fundamental components working in concert.

##### 🏛️ **Pillar 1: The Application Nodes**
The cluster consists of **three identical servers (nodes) by default**, but its architecture is designed to be **scalable to 5, 7, or more nodes**. Each node hosts:
1.  A complete and ready-to-start Mailcow Dockerized installation.
2.  A MariaDB database server, configured to use the shared, persistent storage.
3.  The cluster management service (`Mailcow-HA`).
4.  Keepalived.

At any given time, only one of these nodes is designated as **`MASTER`** and actively handles traffic. The others are in a hot-standby **`BACKUP`** state, ready to take over.

##### 🧠 **Pillar 2: The Brain - The Mailcow-HA Orchestrator**
Our suite of orchestration scripts is the true conductor of the cluster. It uses **Keepalived** as an engine to manage a complex high-availability logic:
*   **Floating IP Management:** The orchestrator is solely responsible for assigning the cluster's unique public IP. This is the single entry point for all users.
*   **Application Monitoring:** An intelligent monitoring script runs at regular intervals to deeply probe the state of the Mailcow stack (running containers, health status, etc.).
*   **Action Orchestration:** Based on the monitor's verdict, the orchestrator makes decisions. If it promotes a node to `MASTER`, it runs a promotion script. If it demotes it to `BACKUP`, it runs a demotion script. In all cases, you are **alerted in real-time** when a failover begins and when it successfully completes.

##### 💾 **Pillar 3: The Centralized and Resilient Database**
The database is no longer a potential point of failure. It uses a dedicated, high-performance block storage volume that operates on the same principle as the main file storage.
*   **Single Master Access:** The MariaDB data volume is a "floating" resource, attached exclusively to the active `MASTER` node. This architecture completely prevents any risk of data corruption (split-brain) since only one server can write to the database at any given time.
*   **High Performance & Security:** All database operations occur over a secure, high-speed private network connecting the server to its storage, ensuring minimal latency and complete isolation of critical data traffic.
*   **Instant Failover:** During a failover, the volume is detached from the old `MASTER` and re-attached to the new one in seconds, ensuring data persistence and consistency without complex synchronization.

##### 🗃️ **Pillar 4: Centralized File Storage**
To ensure perfect consistency and simplify management, a **single shared block storage volume** is used to centralize all of Mailcow's "stateful" data (emails, indexes, certificates, etc.).
*   **Mechanism:** This is a "floating" resource, dynamically attached to the active `MASTER` node. It is never accessible by more than one node at a time.
*   **Benefit:** This centralization provides **enhanced ease of backup and restore**. A full backup can be performed from any node, as the data is always an exact reflection of the service's state.

---

### 3. Anatomy of an Automatic Failover (A to Z)

Here is the precise, step-by-step sequence of events when a failure occurs on the `MASTER` node.

1.  **The Failure:** A critical container (e.g., `dovecot-mailcow`) on the `MASTER` crashes.

2.  **The Detection:**
    *   **Instantly**, the monitoring script detects that the service is no longer in 100% `Operational` state.
    *   It returns an error code to the orchestrator.

3.  **The Decision:**
    *   The orchestrator on the `MASTER` receives the error code. It immediately enters the `FAULT` state and relinquishes its `MASTER` role, notifying the other cluster members.

4.  **The Election:**
    *   The `BACKUP` nodes see that the `MASTER` has disappeared. The node with the highest priority elects itself as the new `MASTER`.

5.  **The Orchestration (on the new `MASTER`)**:
    *   Upon its promotion, the orchestrator runs the promotion script.
    *   This script executes its critical sequence:
        *   a. **Circuit Breaker Check:** It checks if a failover has already occurred on this node less than a user-defined time ago (**1 hour by default**). If so, it stops and sends an alert to prevent a failover loop.
         *   b. **Timestamp Update:** It records the start of the failover to grant a grace period to the monitoring system.
         *   c. **Resource Failover (in parallel):** It launches simultaneous API calls to reassign the **shared volume** and the **Floating IP**.
         *   d. **Wait and Mount:** It waits for confirmation that both operations are complete, then **mounts** the shared volume.
         *   e. **Service Start-up:** It starts the Mailcow Docker containers and ensures they are all fully operational.

6.  **The Grace Period:**
    *   While the promotion script is working (**in just a few seconds**, depending on machine performance), the monitoring script on the new `MASTER` is patient, as it has detected that a failover has just begun.

7.  **The Cleanup (on the old `MASTER`)**:
    *   Meanwhile, the orchestrator on the old `MASTER` (now in `BACKUP` state) runs a demotion script that cleanly stops any remaining containers and **unmounts** the volume.

8.  **Return to Normal:**
    *   On the new `MASTER`, the containers stabilize. The service is once again 100% operational. A success alert is sent to the administrator.

Rest assured, from the moment a failure is detected to the moment the service is available again, **only a few seconds elapse!**

Meanwhile, a **dual monitoring system** (an internal smart monitor and an external one via [Uptime Kuma](https://github.com/louislam/uptime-kuma)) ensures total visibility and instantly alerts the administrator without flooding them with notifications. Additionally, "garbage collector" scripts run at regular intervals to clean up any potential residues.

<br>
<p align="center">
  <strong><a href="./help.md">➡️ Learn more about our Professional services in help.md</a></strong>
</p>
<br>

</details>

<br>

<details>
<summary><strong>🇫🇷 Version Française (cliquer pour déplier)</strong></summary>

### 📄 Architecture Technique Détaillée

### 1. Philosophie et Objectifs

Une instance Mailcow standard, bien que performante, constitue un **point de défaillance unique (SPOF)**. Une panne matérielle, une erreur de mise à jour ou un dysfonctionnement d'un conteneur critique suffit à rendre l'ensemble du service de messagerie indisponible.

L'objectif de **Mailcow-HA** est de transformer cette instance unique en un **cluster résilient de type Actif/Passif**, capable de survivre à la plupart des pannes en basculant automatiquement le service sur un nœud de secours, avec une intervention humaine nulle et une interruption de service minimale.

La solution est conçue comme une **surcouche d'orchestration** qui s'intègre à une installation Mailcow standard sans en modifier le cœur, garantissant ainsi la compatibilité avec les futures mises à jour de Mailcow.

---

### 2. Les Quatre Piliers de l'Infrastructure

La robustesse du cluster repose sur quatre composants fondamentaux qui travaillent de concert.

##### 🏛️ **Pilier 1 : Les Nœuds Applicatifs**
Le cluster est composé de **trois serveurs (nœuds) identiques par défaut**, mais son architecture est conçue pour être **extensible à 5, 7 nœuds ou plus**. Chaque nœud héberge :
1.  Une installation complète de Mailcow Dockerized, prête à démarrer.
2.  Un serveur de base de données MariaDB, configuré pour utiliser le stockage partagé et persistant.
3.  Le service de gestion du cluster (`Mailcow-HA`).
4.  Keepalived.

À tout instant, un seul de ces nœuds est désigné **`MASTER`** et traite activement le trafic. Les autres sont en état de **`BACKUP`** (hot-standby), prêts à prendre le relais.

##### 🧠 **Pilier 2 : Le Cerveau - L'Orchestrateur Mailcow-HA**
Notre suite de scripts d'orchestration est le véritable chef d'orchestre du cluster. Elle utilise **Keepalived** comme moteur pour gérer une logique de haute disponibilité complexe :
*   **Gestion de l'IP Flottante :** L'orchestrateur est le seul responsable de l'assignation de l'IP publique unique du cluster. C'est le point d'entrée de tous les utilisateurs.
*   **Surveillance Applicative :** Un script de surveillance intelligent est exécuté à intervalle régulier pour sonder en profondeur l'état de la pile Mailcow (conteneurs actifs, état de santé, etc.).
*   **Orchestration des Actions :** En fonction du verdict du moniteur, l'orchestrateur prend des décisions. S'il promeut un nœud en `MASTER`, il exécute un script de promotion. S'il le rétrograde en `BACKUP`, il exécute un script de rétrogradation. Dans tous les cas, vous êtes **alerté en temps réel** du début et de la fin de la bascule.

##### 💾 **Pilier 3 : La Base de Données Centralisée et Résiliente**
La base de données n'est plus un point de défaillance. Elle utilise un volume de stockage bloc dédié et performant qui fonctionne sur le même principe que le stockage de fichiers principal.
*   **Accès Master Unique :** Le volume de données de MariaDB est une ressource "flottante", attachée exclusivement au nœud `MASTER` actif. Cette architecture prévient tout risque de corruption de données (split-brain), car un seul serveur peut écrire dans la base de données à un instant T.
*   **Haute Performance et Sécurité :** Toutes les opérations de la base de données s'effectuent via un réseau privé sécurisé et à haute vitesse connectant le serveur à son stockage, garantissant une latence minimale et une isolation complète du trafic de données critiques.
*   **Bascule Instantanée :** Lors d'une bascule, le volume est détaché de l'ancien `MASTER` et rattaché au nouveau en quelques secondes, assurant la persistance et la cohérence des données sans nécessiter de synchronisation complexe.

##### 🗃️ **Pilier 4 : Le Stockage Centralisé des Fichiers**
Pour garantir une cohérence parfaite et simplifier la gestion, un **unique volume de stockage bloc partagé** est utilisé pour centraliser toutes les données "stateful" de Mailcow (e-mails, index, certificats, etc.).
*   **Mécanisme :** Ce volume est une ressource "flottante", attachée dynamiquement au nœud `MASTER` actif. Il n'est jamais accessible par plus d'un nœud à la fois.
*   **Bénéfice :** Cette centralisation garantit une **facilité accrue de sauvegarde et de restauration**. Une sauvegarde complète peut être effectuée depuis n'importe quel nœud, car les données sont toujours le reflet exact de l'état du service.

---

### 3. Anatomie d'une Bascule Automatique (de A à Z)

Voici le déroulement précis, étape par étape, lorsqu'une panne survient sur le nœud `MASTER`.

1.  **La Panne :** Un conteneur critique (ex: `dovecot-mailcow`) sur le `MASTER` plante.

2.  **La Détection :**
    *   **Instantanément**, le script de surveillance détecte que le service n'est plus à 100% l'état `Opérationnel`.
    *   Il retourne un code d'erreur à l'orchestrateur.

3.  **La Décision :**
    *   L'orchestrateur sur le `MASTER` reçoit le code d'erreur. Il entre immédiatement dans l'état `FAULT` et abandonne son rôle, notifiant les autres membres du cluster.

4.  **L'Élection :**
    *   Les nœuds `BACKUP` voient que le `MASTER` a disparu. Le nœud avec la plus haute priorité s'élit lui-même comme nouveau `MASTER`.

5.  **L'Orchestration (sur le nouveau `MASTER`)**:
    *   Dès sa promotion, l'orchestrateur exécute le script de promotion.
    *   Celui-ci exécute sa séquence critique :
         *   a. **Vérification du Disjoncteur :** Il vérifie si une bascule a déjà eu lieu sur ce nœud il y a moins d'un temps défini par l'administrateur (**1 heure par défaut**). Si c'est le cas, il s'arrête et envoie une alerte pour éviter une boucle de basculement.
         *   b. **Mise à Jour des Chronomètres :** Il enregistre le début de la bascule pour accorder une période de grâce à la surveillance.
         *   c. **Bascule des Ressources (en parallèle) :** Il lance les appels pour réassigner le **volume partagé** et l'**IP Flottante** simultanément.
         *   d. **Attente et Montage :** Il attend la confirmation que les deux opérations sont terminées, puis il **monte** le volume partagé.
         *   e. **Démarrage des Services :** Il démarre les services (conteneurs Docker) de Mailcow et s'assure qu'ils sont tous en état de fonctionnement.

6.  **La Période de Grâce :**
    *   Pendant que le script de promotion travaille (**en à peine quelques secondes**, selon la performance des machines), le script de surveillance du nouveau `MASTER` est patient, car il a détecté le début d'une bascule.

7.  **Le Nettoyage (sur l'ancien `MASTER`)**:
    *   Pendant ce temps, l'orchestrateur sur l'ancien `MASTER` (maintenant en `BACKUP`) exécute un script de rétrogradation qui arrête proprement les conteneurs restants et **démonte** le volume.

8.  **Le Retour à la Normale :**
    *   Sur le nouveau `MASTER`, les conteneurs se stabilisent. Le service est de nouveau 100% opérationnel. Une alerte de succès est envoyée à l'administrateur.

Rassurez-vous, entre l'instant où la panne est détectée et la disponibilité à nouveau du service, il ne s'écoule **qu'à peine quelques secondes** !

Pendant ce temps, une **double surveillance** (une interne grâce à un monitoring intelligent et une autre externe via [Uptime Kuma](https://github.com/louislam/uptime-kuma)) garantit une visibilité totale et alerte instantanément l'administrateur sans l'inonder de notifications. De plus, des scripts "ramasse-miettes" s'exécutent à intervalle régulier pour nettoyer les résidus potentiels.

<br>
<p align="center">
  <strong><a href="./help.md">➡️ Découvrez nos services Professionnels dans help.md</a></strong>
</p>
<br>

</details>
<br>
<div align="center">

<table tyle="width: 100%; table-layout:fixed; border:1px solid #ddd; border-collapse:collapse;">
  <tr>
    <th width="50%" style="padding:10px;">🇬🇧 <b>Anglais</b></th>
    <th width="50%" style="padding:10px;">🇫🇷 <b>Français</b></th>
  </tr>
  <tr>
    <td>Youtube demonstration, mailcow ISP - High availability in action. <b>Failure detection and automatic failover</b></td>
    <td>Démonstration Youtube, mailcow ISP - Haute disponibilité en action. <b>Détection de panne et basculement automatique</b></td>
  </tr>
  <tr>
    <td colspan="2" align="center" style="padding:15px; position:relative;"><br>
      <a href="https://www.youtube.com/watch?v=IloqWSzUiaI" target="_new">
        <img src="maicow-isp-ha.png" alt="mailcow ISP - High availability in action" height="100%" width="90%">
      </a>
    <br><br>
    </td>
  </tr>
</table>

<br><br>

<table style="width: 100%; table-layout:fixed; border:1px solid #ddd; border-collapse:collapse;">
  <tr>
    <th width="50%">🇬🇧 <b>Anglais</b></th>
    <th width="50%">🇫🇷 <b>Français</b></th>
  </tr>
  <tr>
    <td>Youtube Demo, Mailcow ISP - <b>Automatic Registration</b></td>
    <td>Démonstration Youtube, mailcow ISP - <b>Inscription automatique</b></td>
  </tr>
  <tr>
    <td colspan="2" align="center">
        <br>
      <a href="https://www.youtube.com/watch?v=wEbo20yiaYA" target="_new">
         <img src="signup.png" alt="Mailcow ISP" style="height: 50%; width: 50%;">
      </a>
   <br><br>
    </td>
  </tr>
</table>

</div>
