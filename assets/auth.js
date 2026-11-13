// Adds a "Show" button to password boxes on the login, register and account pages.
document.querySelectorAll('.pw').forEach(function (box) {
  var input = box.querySelector('input'), b = document.createElement('button');
  b.type = 'button'; b.textContent = 'Show'; b.setAttribute('aria-label', 'Show password');
  b.addEventListener('click', function () {
    var hidden = input.type === 'password';
    input.type = hidden ? 'text' : 'password';
    b.textContent = hidden ? 'Hide' : 'Show';
    b.setAttribute('aria-label', hidden ? 'Hide password' : 'Show password');
  });
  box.append(b);
});
