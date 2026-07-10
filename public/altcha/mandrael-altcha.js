/*
 * Headless ALTCHA-Proof-of-Work-Solver. Holt eine frische Challenge vom Bundle-Endpoint, laesst den
 * SHA-256-PoW off-thread im worker.js rechnen und schreibt die Loesung in ein Hidden-Field. Schlaegt
 * Fetch oder PoW fehl, bleibt das Feld leer -> der Server entscheidet; der Submit wird NIE blockiert.
 */
(function () {
  'use strict';

  document.querySelectorAll('input[data-mandrael-altcha]').forEach(function (input) {
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
  });
})();
