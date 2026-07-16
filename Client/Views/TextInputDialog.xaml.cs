using System.Windows;

namespace JustLinkIt.Client.Views;

/// <summary>
/// Interaction logic for TextInputDialog.xaml
/// </summary>
public partial class TextInputDialog : Window
{
    public string InputText => InputTextBox.Text;

    public TextInputDialog(string title, string prompt, string defaultText)
    {
        InitializeComponent();
        Title = title;
        PromptText.Text = prompt;
        InputTextBox.Text = defaultText;
        InputTextBox.SelectAll();
        Loaded += (_, _) => InputTextBox.Focus();
    }

    private void OkButton_Click(object sender, RoutedEventArgs e)
    {
        DialogResult = true;
    }
}
