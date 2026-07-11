/*
 * Headless ALTCHA-Proof-of-Work-Solver. Holt eine frische Challenge vom Bundle-Endpoint, laesst den
 * SHA-256-PoW off-thread im worker.js rechnen und schreibt die Loesung in ein Hidden-Field. Schlaegt
 * Fetch oder PoW fehl, bleibt das Feld leer -> der Server entscheidet; der Submit wird NIE blockiert.
 */
(function () {
  'use strict';

  function solve(input) {
    fetch(input.dataset.challengeurl, { credentials: 'same-origin' })
      .then(function (response) { return response.json(); })
      .then(function (challenge) {
        var worker = new Worker(input.dataset.workerurl);

        worker.onmessage = function (event) {
          if (event.data && typeof event.data.number === 'number') {
            input.value = btoa(JSON.stringify({
              algorithm: challenge.algorithm,
              challenge: challenge.challenge,
              number: event.data.number,
              salt: challenge.salt,
              signature: challenge.signature
            }));
          }
          worker.terminate();
        };

        worker.postMessage({
          type: 'work',
          payload: { algorithm: challenge.algorithm, challenge: challenge.challenge, salt: challenge.salt },
          max: challenge.maxnumber,
          start: 0
        });
      })
      .catch(function () { /* leer lassen: der Server entscheidet, der Submit wird nie blockiert */ });
  }

  function solveAll() {
    document.querySelectorAll('input[data-mandrael-altcha]').forEach(solve);
  }

  solveAll();

  // Nach einer bfcache-Wiederherstellung (Zurueck/Vorwaerts) wurde das Script nicht neu ausgefuehrt und
  // die geladene Loesung ist evtl. abgelaufen oder bereits verbraucht -> mit frischer Challenge neu loesen.
  window.addEventListener('pageshow', function (event) {
    if (event.persisted) {
      solveAll();
    }
  });

  // Lange offene Formulare: die Loesung vor Ablauf der serverseitigen Expiry (3600 s) erneuern, damit ein
  // spaeter Absender mit Turnstile-Fehlschlag keinen abgelaufenen Proof mitschickt. Feuert bei kurzen
  // Sitzungen nie.
  setInterval(solveAll, 45 * 60 * 1000);
})();
