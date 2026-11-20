
const API = 'api/';

async function chamarApi(arquivo, opcoes = {}) {
  const resposta = await fetch(API + arquivo, {
    credentials: 'same-origin',
    ...opcoes
  });

  const texto = await resposta.text();

  let dados;

  try {
    dados = JSON.parse(texto);
  } catch (erro) {
    console.error(
      'Resposta crua de ' + arquivo +
      ' (status ' + resposta.status + '):\n' + texto
    );

    throw new Error('O servidor devolveu uma resposta inválida');
  }

  if (!resposta.ok) {
    throw new Error(dados.erro || 'Algo deu errado');
  }

  return dados;
}

function enviarJson(arquivo, corpo, metodo = 'POST') {
  return chamarApi(arquivo, {
    method: metodo,
    headers: {
      'Content-Type': 'application/json'
    },
    body: JSON.stringify(corpo)
  });
}

function mostrarMensagem(elemento, texto, tipo = 'erro') {
  elemento.textContent = texto;
  elemento.className = 'mensagem ' + tipo;
}

async function usuarioLogado() {
  const dados = await chamarApi('me.php');
  return dados.logado ? dados : null;
}
